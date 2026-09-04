@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    <header class="panel topbar">
        <div><h1>Other Servers</h1><p class="muted">Track OS update status for Ubuntu/EC2 servers outside WordPress.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if(session('otherServerCredentials'))
        <section class="panel content" style="margin:16px 0;border-color:#b66a00;">
            <h2>Copy Agent Credentials Now</h2>
            <p class="muted">These credentials are shown once. Save them into <code>/etc/serverdashboard/agent.env</code> on the server, then they will no longer be displayed here.</p>
            <p><strong>Server:</strong> {{ session('otherServerCredentials.serverName') }}</p>
            <p><strong>Endpoint (DASHBOARD_ENDPOINT):</strong> <code>{{ session('otherServerCredentials.endpoint') }}</code></p>
            <p><strong>Token (DASHBOARD_TOKEN):</strong> <code>{{ session('otherServerCredentials.token') }}</code></p>
        </section>
    @endif

    <section class="panel content" style="margin-top:16px;">
        <h2>Add Server</h2>
        <form method="POST" action="{{ route('other-servers.store') }}" class="form-grid">
            @csrf
            <div><label for="other-server-name">Server name</label><input id="other-server-name" name="name" value="{{ old('name') }}" required><span class="error">@error('name'){{ $message }}@enderror</span></div>
            <div><label for="other-server-hostname">Hostname / EC2 instance (optional)</label><input id="other-server-hostname" name="hostname" placeholder="ec2-54-206-154-130.ap-southeast-2.compute.amazonaws.com" value="{{ old('hostname') }}"><span class="error">@error('hostname'){{ $message }}@enderror</span></div>
            <div><label for="other-server-instance-id">AWS instance ID (optional, enables manual patch)</label><input id="other-server-instance-id" name="awsInstanceId" placeholder="i-0a1b2c3d4e5f6789a" value="{{ old('awsInstanceId') }}"><span class="error">@error('awsInstanceId'){{ $message }}@enderror</span></div>
            <div style="align-self:end;"><button type="submit">Add server</button></div>
        </form>
    </section>

    <section class="panel content" style="margin-top:14px;">
        <h2>Registered Servers</h2>
        <div class="scroll">
            <table>
                <thead><tr><th>Server</th><th>Status</th><th>OS</th><th>Last report</th><th>Updates</th><th>Security updates</th><th>Reboot</th><th>Security actions</th></tr></thead>
                <tbody>
                @forelse($otherServers as $server)
                    <tr>
                        <td><strong>{{ $server->name }}</strong>@if($server->hostname)<br><span class="muted">{{ $server->hostname }}</span>@endif @if($server->aws_instance_id)<br><span class="muted">{{ $server->aws_instance_id }}</span>@endif</td>
                        <td><span class="badge {{ $server->is_active ? 'ok' : 'danger' }}">{{ $server->is_active ? 'enabled' : 'disabled' }}</span></td>
                        <td>{{ $server->os_name ?? '—' }}</td>
                        <td>{{ $server->last_reported_at?->diffForHumans() ?? 'No report yet' }}</td>
                        <td>{{ $server->total_updates }}</td>
                        <td><span class="badge {{ $server->security_updates > 0 ? 'danger' : 'ok' }}">{{ $server->security_updates }}</span></td>
                        <td><span class="badge {{ $server->reboot_required ? 'warning' : 'ok' }}">{{ $server->reboot_required ? 'required' : 'no' }}</span></td>
                        <td>
                            <div class="actions">
                                <form method="POST" action="{{ route('other-servers.test-connection', $server) }}">@csrf<button type="submit" class="secondary" @if(! $server->hostname) disabled title="Set a hostname to test connectivity" @endif>Test connection</button></form>
                                <form method="POST" action="{{ route('other-servers.patch-now', $server) }}">@csrf<button type="submit" @if(! $server->aws_instance_id) disabled title="Set an AWS instance ID to enable manual patch checks" @endif>Patch now</button></form>
                                <form method="POST" action="{{ route('other-servers.rotate-token', $server) }}">@csrf<button type="submit">Rotate token</button></form>
                                <form method="POST" action="{{ route('other-servers.toggle-active', $server) }}">@csrf<button type="submit" class="secondary">{{ $server->is_active ? 'Disable' : 'Enable' }}</button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">No servers registered yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:10px;">"Test connection" resolves the server's hostname and checks that TCP port 22 (SSH) accepts connections &mdash; it does not log in or use any credentials. Loopback and link-local addresses (including the cloud metadata service) are always rejected.</p>
        <p class="muted">"Patch now" runs the update-check script immediately via AWS Systems Manager Run Command (no SSH keys stored by the dashboard) instead of waiting for the agent's 6-hour timer. It requires the SSM Agent and an IAM instance profile with Systems Manager access on the target instance, plus an AWS instance ID set above.</p>
    </section>

    <section class="panel content" style="margin-top:14px;">
        <h2>Connecting a Server &ndash; Push Agent (Recommended)</h2>
        <p class="muted">The dashboard never opens an inbound connection to your servers. Instead, each server runs a small agent on a schedule that pushes its update status out over HTTPS using the one-time token above. This means no SSH access, no stored SSH keys, and no extra inbound firewall rules are needed for reporting.</p>

        <h3>1. Store the credentials securely (as root)</h3>
        @verbatim
        <textarea readonly rows="8" style="width:100%;font-family:monospace;font-size:12px;">sudo mkdir -p /etc/serverdashboard
sudo tee /etc/serverdashboard/agent.env >/dev/null <<'EOF'
DASHBOARD_ENDPOINT="https://your-dashboard-domain/ingest/other-server/<slug>/report"
DASHBOARD_TOKEN="<paste the one-time token here>"
EOF
sudo chown root:root /etc/serverdashboard/agent.env
sudo chmod 600 /etc/serverdashboard/agent.env</textarea>
        @endverbatim
        <p class="muted">Use the exact <strong>Endpoint</strong> and <strong>Token</strong> shown once above. <code>chmod 600</code> ensures only root can read the token.</p>

        <h3>2. Install the agent script</h3>
        @verbatim
        <textarea readonly rows="36" style="width:100%;font-family:monospace;font-size:12px;">sudo tee /usr/local/bin/serverdashboard-agent.sh >/dev/null <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/etc/serverdashboard/agent.env"
[[ -f "$CONFIG_FILE" ]] || { echo "Missing $CONFIG_FILE" >&2; exit 1; }
source "$CONFIG_FILE"
: "${DASHBOARD_ENDPOINT:?not set}"
: "${DASHBOARD_TOKEN:?not set}"

if [[ -x /usr/lib/update-notifier/apt-check ]]; then
    COUNTS=$(/usr/lib/update-notifier/apt-check 2>&1 >/dev/null)
    TOTAL=$(cut -d';' -f1 <<< "$COUNTS")
    SECURITY=$(cut -d';' -f2 <<< "$COUNTS")
else
    TOTAL=$(apt list --upgradable 2>/dev/null | grep -c '^[^L]')
    SECURITY=$(apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst.*security')
fi

REBOOT=false
[[ -f /var/run/reboot-required ]] && REBOOT=true

OS_NAME=$(. /etc/os-release; echo "$PRETTY_NAME")
CHECKED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

BODY=$(printf '{"osName":"%s","totalUpdates":%d,"securityUpdates":%d,"rebootRequired":%s,"checkedAt":"%s"}' \
    "$OS_NAME" "$TOTAL" "$SECURITY" "$REBOOT" "$CHECKED_AT")

TIMESTAMP=$(date +%s)
NONCE=$(openssl rand -hex 16)
SIGNATURE=$(printf '%s.%s.%s' "$TIMESTAMP" "$NONCE" "$BODY" \
    | openssl dgst -sha256 -hmac "$DASHBOARD_TOKEN" | awk '{print $2}')

curl --fail --silent --show-error --tlsv1.2 \
    -H "Content-Type: application/json" \
    -H "X-Server-Monitor-Timestamp: $TIMESTAMP" \
    -H "X-Server-Monitor-Nonce: $NONCE" \
    -H "X-Server-Monitor-Signature: $SIGNATURE" \
    --data-raw "$BODY" \
    "$DASHBOARD_ENDPOINT"
EOF
sudo chmod 750 /usr/local/bin/serverdashboard-agent.sh
sudo chown root:root /usr/local/bin/serverdashboard-agent.sh</textarea>
        @endverbatim
        <p class="muted">The script never needs <code>sudo</code>/root privileges at runtime &mdash; <code>apt-check</code> and the package lists it reads are world-readable, and Ubuntu's own <code>apt-daily.timer</code> already refreshes them. The token only grants permission to submit update counts, nothing else.</p>

        <h3>3. Run it on a schedule with systemd (not root cron)</h3>
        @verbatim
        <textarea readonly rows="28" style="width:100%;font-family:monospace;font-size:12px;">sudo tee /etc/systemd/system/serverdashboard-agent.service >/dev/null <<'EOF'
[Unit]
Description=ServerDashboard update report

[Service]
Type=oneshot
User=nobody
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ExecStart=/usr/local/bin/serverdashboard-agent.sh
EOF

sudo tee /etc/systemd/system/serverdashboard-agent.timer >/dev/null <<'EOF'
[Unit]
Description=Run ServerDashboard update report every 6 hours

[Timer]
OnBootSec=5min
OnUnitActiveSec=6h
RandomizedDelaySec=15min
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now serverdashboard-agent.timer</textarea>
        @endverbatim
        <p class="muted">The service runs as the unprivileged <code>nobody</code> user with <code>NoNewPrivileges</code> and a locked-down filesystem, so a compromised script or leaked token cannot be used to run commands on the box &mdash; it can only submit an update report through the signed endpoint.</p>

        <h3>Why this is secure</h3>
        <ul>
            <li><strong>Outbound-only:</strong> the agent pushes data out over HTTPS; the dashboard never opens an inbound connection or stores SSH keys for your servers.</li>
            <li><strong>Signed &amp; replay-proof:</strong> every report is HMAC-SHA256 signed with the per-server token, timestamped, and includes a single-use nonce &mdash; requests older than 5 minutes or reusing a nonce are rejected.</li>
            <li><strong>Least privilege:</strong> the token can only submit update counts to one server's endpoint; the agent runs unprivileged and needs no <code>sudo</code> rights.</li>
            <li><strong>TLS enforced:</strong> use an HTTPS dashboard URL only. Do not disable certificate verification in the script.</li>
            <li><strong>Secrets at rest:</strong> the token file is <code>chmod 600</code>, root-owned, and never appears in shell history, process arguments, or logs.</li>
            <li><strong>Rotation:</strong> use "Rotate token" above immediately if a token may have leaked; the previous token stops working instantly.</li>
        </ul>

        <h2 style="margin-top:18px;">Hardening Direct SSH Access to These Servers</h2>
        <p class="muted">The agent above removes the need for SSH just to check for updates, but you'll still SSH in for real administration. Harden that path too:</p>
        <ul>
            <li>Restrict the EC2 security group's SSH (22) rule to specific admin IPs/CIDRs &mdash; never <code>0.0.0.0/0</code>.</li>
            <li>Use key-based authentication only: set <code>PasswordAuthentication no</code> and <code>PermitRootLogin no</code> in <code>/etc/ssh/sshd_config</code>.</li>
            <li>Prefer AWS Systems Manager Session Manager over exposing port 22 at all &mdash; it needs no open inbound port and logs every session.</li>
            <li>Keep the OS patched using the counts on this page, and enable <code>unattended-upgrades</code> for security patches.</li>
            <li>Enable <code>ufw</code>/security-group rules to deny all inbound by default, and consider <code>fail2ban</code> for brute-force protection on any exposed service.</li>
        </ul>
    </section>
@endsection
