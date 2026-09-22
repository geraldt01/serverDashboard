@extends('layouts.app')

@section('content')
    <style>
        dialog.other-server-details-dialog { border:none; border-radius:8px; padding:0; width:min(560px,90vw); }
        dialog.other-server-details-dialog::backdrop { background:rgba(15,23,42,.45); }
        dialog.other-server-details-dialog .modal-body { padding:20px; }
        dialog.other-server-details-dialog ul { margin:10px 0 0; padding-left:18px; max-height:60vh; overflow:auto; font-size:13px; }
        dialog.other-server-details-dialog .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:14px; }
        button.other-server-details-dialog {
            background:#fff;
            color:var(--ink);
            border:none;
            box-shadow:none;
            display:block;
            font-size:10px;
            text-align:left;
            padding:5px 0 0;
            height:13px;
            position:absolute;
        }
    </style>
    @include('partials.admin-sidebar')
    <header class="panel topbar">
        <div><h1>Other Servers</h1><p class="muted">Track OS update status for Ubuntu/CentOS/RHEL EC2 servers outside WordPress.</p></div>
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
                <thead><tr><th>Server</th><th>Status</th><th>OS</th><th>Last report</th><th>Updates</th><th>Security updates</th><th>Reboot</th><th>PHP</th><th>Security actions</th></tr></thead>
                <tbody>
                @forelse($otherServers as $server)
                    <tr>
                        <td><strong>{{ $server->name }}</strong>@if($server->hostname)<br><span class="muted">{{ $server->hostname }}</span>@endif @if($server->aws_instance_id)<br><span class="muted">{{ $server->aws_instance_id }}</span>@endif</td>
                        <td><span class="badge {{ $server->is_active ? 'ok' : 'danger' }}">{{ $server->is_active ? 'enabled' : 'disabled' }}</span></td>
                        <td>{{ $server->os_name ?? '—' }}</td>
                        <td>{{ $server->last_reported_at?->diffForHumans() ?? 'No report yet' }}</td>
                        <td style="position:relative;">
                            {{ $server->total_updates }}
                            @if($server->update_details)
                                <button type="button" class="other-server-details-dialog" data-open-updates="updates-dialog-{{ $server->id }}">View details</button>
                                <dialog id="updates-dialog-{{ $server->id }}" class="other-server-details-dialog">
                                    <div class="modal-body">
                                        <h2>Pending updates &ndash; {{ $server->name }}</h2>
                                        <p class="muted">{{ $server->total_updates }} update(s), {{ $server->security_updates }} security. Last report: {{ $server->last_reported_at?->diffForHumans() ?? 'No report yet' }}.</p>
                                        <ul>
                                            @foreach(explode("\n", trim($server->update_details)) as $line)
                                                @if(trim($line) !== '')
                                                    <li>{{ $line }}</li>
                                                @endif
                                            @endforeach
                                        </ul>
                                        <div class="modal-actions"><button type="button" class="secondary" data-close-updates>Close</button></div>
                                    </div>
                                </dialog>
                            @endif
                        </td>
                        <td style="position:relative;">
                            <span class="badge {{ $server->security_updates > 0 ? 'danger' : 'ok' }}">{{ $server->security_updates }}</span>
                            @if($server->security_update_details)
                                <button type="button" class="other-server-details-dialog" data-open-security="security-dialog-{{ $server->id }}">View details</button>
                                <dialog id="security-dialog-{{ $server->id }}" class="other-server-details-dialog">
                                    <div class="modal-body">
                                        <h2>Security updates &ndash; {{ $server->name }}</h2>
                                        <p class="muted">{{ $server->security_updates }} security update(s). Last report: {{ $server->last_reported_at?->diffForHumans() ?? 'No report yet' }}.</p>
                                        <ul>
                                            @foreach(explode("\n", trim($server->security_update_details)) as $line)
                                                @if(trim($line) !== '')
                                                    <li>{{ $line }}</li>
                                                @endif
                                            @endforeach
                                        </ul>
                                        <div class="modal-actions"><button type="button" class="secondary" data-close-security>Close</button></div>
                                    </div>
                                </dialog>
                            @endif
                        </td>
                        <td><span class="badge {{ $server->reboot_required ? 'warning' : 'ok' }}">{{ $server->reboot_required ? 'required' : 'no' }}</span></td>
                        <td>{{ $server->php_version ?? '—' }}@if($server->php_update_available)<br><span class="badge warning">update available</span>@endif</td>
                        <td>
                            <div class="actions">
                                <form method="POST" action="{{ route('other-servers.test-connection', $server) }}">@csrf<button type="submit" class="secondary" @if(! $server->hostname) disabled title="Set a hostname to test connectivity" @endif>Test connection</button></form>
                                <form method="POST" action="{{ route('other-servers.patch-now', $server) }}">@csrf<button type="submit" @if(! $server->aws_instance_id) disabled title="Set an AWS instance ID to enable manual patch checks" @endif>Patch now</button></form>
                                <form method="POST" action="{{ route('other-servers.rotate-token', $server) }}">@csrf<button type="submit">Rotate token</button></form>
                                <form method="POST" action="{{ route('other-servers.toggle-active', $server) }}">@csrf<button type="submit" class="{{ $server->is_active ? 'btn-danger' : 'secondary' }}">{{ $server->is_active ? 'Disable' : 'Enable' }}</button></form>
                            </div>
                            <details style="margin-top:8px;">
                                <summary class="muted" style="cursor:pointer;">Edit name / hostname / instance ID</summary>
                                <form method="POST" action="{{ route('other-servers.update-details', $server) }}" class="form-grid" style="margin-top:8px;">
                                    @csrf
                                    <div><label for="other-server-name-{{ $server->id }}">Server name</label><input id="other-server-name-{{ $server->id }}" name="name" value="{{ old('name', $server->name) }}" required><span class="error">@error('name'){{ $message }}@enderror</span></div>
                                    <div><label for="other-server-hostname-{{ $server->id }}">Hostname / EC2 instance</label><input id="other-server-hostname-{{ $server->id }}" name="hostname" placeholder="ec2-54-206-154-130.ap-southeast-2.compute.amazonaws.com" value="{{ old('hostname', $server->hostname) }}"><span class="error">@error('hostname'){{ $message }}@enderror</span></div>
                                    <div><label for="other-server-instance-id-{{ $server->id }}">AWS instance ID</label><input id="other-server-instance-id-{{ $server->id }}" name="awsInstanceId" placeholder="i-0a1b2c3d4e5f6789a" value="{{ old('awsInstanceId', $server->aws_instance_id) }}"><span class="error">@error('awsInstanceId'){{ $message }}@enderror</span></div>
                                    <div style="align-self:end;"><button type="submit" class="secondary">Save</button></div>
                                </form>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">No servers registered yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:10px;">"Test connection" resolves the server's hostname and checks that TCP port 22 (SSH) accepts connections &mdash; it does not log in or use any credentials. Loopback and link-local addresses (including the cloud metadata service) are always rejected.</p>
        <p class="muted">"Patch now" runs the update-check script immediately via AWS Systems Manager Run Command (no SSH keys stored by the dashboard) instead of waiting for the agent's 3-hour timer. It requires the SSM Agent and an IAM instance profile with Systems Manager access on the target instance, plus an AWS instance ID set above.</p>
    </section>

    <section class="panel content" style="margin-top:14px;">
        <h2>Connecting a Server &ndash; Push Agent (Recommended)</h2>
        <p class="muted">The dashboard never opens an inbound connection to your servers. Instead, each server runs a small agent on a schedule that pushes its update status out over HTTPS using the one-time token above. This means no SSH access, no stored SSH keys, and no extra inbound firewall rules are needed for reporting.</p>

        <h3>1. Create a dedicated service account and store the credentials securely (as root)</h3>
        @verbatim
        <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;">sudo useradd --system --no-create-home --shell /usr/sbin/nologin serverdashboard 2>/dev/null || true
sudo mkdir -p /etc/serverdashboard
sudo tee /etc/serverdashboard/agent.env >/dev/null <<'EOF'
DASHBOARD_ENDPOINT="https://your-dashboard-domain/ingest/other-server/<slug>/report"
DASHBOARD_TOKEN="<paste the one-time token here>"
EOF
sudo chown serverdashboard:serverdashboard /etc/serverdashboard/agent.env
sudo chmod 600 /etc/serverdashboard/agent.env</textarea>
        @endverbatim
        <p class="muted">Use the exact <strong>Endpoint</strong> and <strong>Token</strong> shown once above. The service in step 3 runs as a dedicated, unprivileged <code>serverdashboard</code> system account (not the shared <code>nobody</code> account, which is also used by other services on the box) so the file is owned by <code>serverdashboard</code>; <code>chmod 600</code> ensures no other account, including <code>nobody</code>, can read the token.</p>

        <h3>2. Install the agent script</h3>
        @verbatim
        <textarea readonly rows="48" style="width:100%;font-family:monospace;font-size:12px;">sudo tee /usr/local/bin/serverdashboard-agent.sh >/dev/null <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/etc/serverdashboard/agent.env"
[[ -f "$CONFIG_FILE" ]] || { echo "Missing $CONFIG_FILE" >&2; exit 1; }

# Parse KEY="VALUE" pairs without sourcing the file, so a writable/tampered
# config can never execute arbitrary shell code.
read_config_value() {
    grep -E "^$1=" "$CONFIG_FILE" | tail -n1 | cut -d'=' -f2- | sed -e 's/^"//' -e 's/"$//'
}
DASHBOARD_ENDPOINT=$(read_config_value DASHBOARD_ENDPOINT)
DASHBOARD_TOKEN=$(read_config_value DASHBOARD_TOKEN)
: "${DASHBOARD_ENDPOINT:?not set}"
: "${DASHBOARD_TOKEN:?not set}"
[[ "$DASHBOARD_ENDPOINT" == https://* ]] || { echo "DASHBOARD_ENDPOINT must use https://" >&2; exit 1; }

PKG_MGR=""
if command -v apt-get >/dev/null 2>&1; then
    PKG_MGR="apt"
elif command -v dnf >/dev/null 2>&1; then
    PKG_MGR="dnf"
elif command -v yum >/dev/null 2>&1; then
    PKG_MGR="yum"
fi

case "$PKG_MGR" in
    apt)
        if [[ -x /usr/lib/update-notifier/apt-check ]]; then
            COUNTS=$(/usr/lib/update-notifier/apt-check 2>&1 >/dev/null)
            TOTAL=$(cut -d';' -f1 <<< "$COUNTS")
            SECURITY=$(cut -d';' -f2 <<< "$COUNTS")
        else
            TOTAL=$(apt list --upgradable 2>/dev/null | grep -c '^[^L]' || true)
            SECURITY=$(apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst.*security' || true)
        fi
        UPDATE_DETAILS=$(apt list --upgradable 2>/dev/null | tail -n +2 | head -n 50 || true)
        SECURITY_UPDATE_DETAILS=$(apt-get -s dist-upgrade 2>/dev/null | grep '^Inst.*security' | head -n 50 || true)
        REBOOT=false
        [[ -f /var/run/reboot-required ]] && REBOOT=true
        ;;
    dnf|yum)
        # check-update exits 100 when updates are available; harmless here since grep (not check-update) is the last stage of the pipe.
        TOTAL=$("$PKG_MGR" -q check-update 2>/dev/null | grep -c -E '^[A-Za-z0-9]' || true)
        SECURITY=$("$PKG_MGR" -q check-update --security 2>/dev/null | grep -c -E '^[A-Za-z0-9]' || true)
        UPDATE_DETAILS=$("$PKG_MGR" -q check-update 2>/dev/null | grep -E '^[A-Za-z0-9]' | head -n 50 || true)
        SECURITY_UPDATE_DETAILS=$("$PKG_MGR" -q check-update --security 2>/dev/null | grep -E '^[A-Za-z0-9]' | head -n 50 || true)
        REBOOT=false
        if command -v needs-restarting >/dev/null 2>&1; then
            needs-restarting -r >/dev/null 2>&1 || REBOOT=true
        fi
        ;;
    *)
        TOTAL=0
        SECURITY=0
        UPDATE_DETAILS=""
        SECURITY_UPDATE_DETAILS=""
        REBOOT=false
        ;;
esac

OS_NAME=$(. /etc/os-release; echo "$PRETTY_NAME")

PHP_VERSION=""
PHP_UPDATE_AVAILABLE=false
if command -v php >/dev/null 2>&1; then
    PHP_VERSION=$(php -r 'echo PHP_VERSION;' 2>/dev/null) || true
    if [[ -z "$PHP_VERSION" ]]; then
        # Some restricted/suEXEC php CLI wrappers (common on WHM/cPanel boxes) reject -r; fall back to parsing `php -v`.
        PHP_VERSION=$(php -v 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)
    fi
    case "$PKG_MGR" in
        apt)
            PHP_PKG_UPGRADES=$(apt list --upgradable 2>/dev/null | grep -c -E '^php[0-9.]*(-|/| )' || true)
            ;;
        dnf|yum)
            PHP_PKG_UPGRADES=$("$PKG_MGR" -q check-update 2>/dev/null | grep -c -E '^php[0-9.]*[-.]' || true)
            ;;
        *)
            PHP_PKG_UPGRADES=0
            ;;
    esac
    [[ "$PHP_PKG_UPGRADES" -gt 0 ]] && PHP_UPDATE_AVAILABLE=true
fi

CHECKED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# JSON-escape the (possibly multi-line) package lists: backslashes first, then quotes, then real newlines -> literal \n.
UPDATE_DETAILS_JSON=$(printf '%s' "$UPDATE_DETAILS" | sed -e ':a' -e 'N' -e '$!ba' -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\n/\\n/g')
SECURITY_UPDATE_DETAILS_JSON=$(printf '%s' "$SECURITY_UPDATE_DETAILS" | sed -e ':a' -e 'N' -e '$!ba' -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\n/\\n/g')

BODY=$(printf '{"osName":"%s","totalUpdates":%d,"securityUpdates":%d,"updateDetails":"%s","securityUpdateDetails":"%s","rebootRequired":%s,"phpVersion":"%s","phpUpdateAvailable":%s,"checkedAt":"%s"}' \
    "$OS_NAME" "$TOTAL" "$SECURITY" "$UPDATE_DETAILS_JSON" "$SECURITY_UPDATE_DETAILS_JSON" "$REBOOT" "$PHP_VERSION" "$PHP_UPDATE_AVAILABLE" "$CHECKED_AT")

TIMESTAMP=$(date +%s)
NONCE=$(RANDFILE=/dev/null openssl rand -hex 16)
SIGNATURE=$(printf '%s.%s.%s' "$TIMESTAMP" "$NONCE" "$BODY" \
    | RANDFILE=/dev/null openssl dgst -sha256 -hmac "$DASHBOARD_TOKEN" | awk '{print $2}')

curl --fail --silent --show-error --tlsv1.2 \
    --proto '=https' --proto-redir '=https' \
    -H "Content-Type: application/json" \
    -H "X-Server-Monitor-Timestamp: $TIMESTAMP" \
    -H "X-Server-Monitor-Nonce: $NONCE" \
    -H "X-Server-Monitor-Signature: $SIGNATURE" \
    --data "$BODY" \
    "$DASHBOARD_ENDPOINT"
EOF
sudo chmod 755 /usr/local/bin/serverdashboard-agent.sh
sudo chown root:root /usr/local/bin/serverdashboard-agent.sh</textarea>
        @endverbatim
        <p class="muted">The script never needs <code>sudo</code>/root privileges at runtime on standard distro configurations &mdash; <code>apt-check</code>/<code>yum</code>/<code>dnf</code> and the package lists they read are world-readable, and the OS's own daily update-check timer already refreshes them. (Verify this holds on hardened distributions or custom package-metadata setups before relying on it.) <code>755</code> lets the unprivileged <code>serverdashboard</code> service account (step 3) execute it, but only root can modify it. <code>--proto '=https'</code> refuses to run if the endpoint is ever misconfigured to a non-HTTPS URL, preventing protocol downgrade. The token only grants permission to submit update counts, nothing else.</p>

        <h3>3. Run it on a schedule with systemd (not root cron)</h3>
        @verbatim
        <textarea readonly rows="28" style="width:100%;font-family:monospace;font-size:12px;">sudo tee /etc/systemd/system/serverdashboard-agent.service >/dev/null <<'EOF'
[Unit]
Description=ServerDashboard update report

[Service]
Type=oneshot
User=serverdashboard
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
PrivateDevices=yes
RestrictAddressFamilies=AF_INET AF_INET6
CapabilityBoundingSet=
LockPersonality=yes
MemoryDenyWriteExecute=yes
ExecStart=/usr/local/bin/serverdashboard-agent.sh
EOF

sudo tee /etc/systemd/system/serverdashboard-agent.timer >/dev/null <<'EOF'
[Unit]
Description=Run ServerDashboard update report every 3 hours

[Timer]
OnBootSec=5min
OnUnitActiveSec=3h
RandomizedDelaySec=15min
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now serverdashboard-agent.timer</textarea>
        @endverbatim
        <p class="muted">The service runs as the dedicated, unprivileged <code>serverdashboard</code> user with <code>NoNewPrivileges</code>, a locked-down filesystem, and additional kernel/device/namespace restrictions, so a compromised script or leaked token cannot be used to run commands on the box &mdash; it can only submit an update report through the signed endpoint.</p>

        <h3>Why this is secure</h3>
        <ul>
            <li><strong>Outbound-only:</strong> the agent pushes data out over HTTPS; the dashboard never opens an inbound connection or stores SSH keys for your servers.</li>
            <li><strong>Signed &amp; replay-proof:</strong> every report is HMAC-SHA256 signed with the per-server token, timestamped, and includes a single-use nonce &mdash; the ingest endpoint rejects requests older than 5 minutes and enforces nonce uniqueness at the database level (a duplicate nonce insert is rejected as a replay), so this is enforced server-side, not just claimed by the agent.</li>
            <li><strong>Least privilege:</strong> the token can only submit update counts to one server's endpoint; the agent runs as a dedicated unprivileged system account and needs no <code>sudo</code> rights.</li>
            <li><strong>TLS enforced:</strong> use an HTTPS dashboard URL only; the agent refuses to run against a non-HTTPS endpoint and pins the curl request to the HTTPS protocol (<code>--proto '=https'</code>) to prevent downgrade. Do not disable certificate verification in the script.</li>
            <li><strong>Secrets at rest:</strong> the token file is <code>chmod 600</code>, owned only by the dedicated <code>serverdashboard</code> account (not <code>root</code>, not the shared <code>nobody</code> account), parsed with a safe line-by-line reader instead of shell <code>source</code> (so a tampered config file can never execute arbitrary code), and never appears in shell history, process arguments, or logs.</li>
            <li><strong>Rotation:</strong> use "Rotate token" above immediately if a token may have leaked; the previous token stops working instantly.</li>
            <li><strong>Optional hardening:</strong> for internal/enterprise deployments with a private CA, consider pinning the dashboard's certificate with curl's <code>--pinnedpubkey</code> option for defense against a compromised public CA; this isn't enabled by default since it requires re-pinning whenever the dashboard's certificate is rotated.</li>
        </ul>

        <h2 style="margin-top:18px;">Connecting a Windows Server (PowerShell Agent)</h2>
        <p class="muted">Same push model as above &mdash; no inbound access needed &mdash; but implemented in PowerShell using the built-in Windows Update Agent API, run on a schedule via Task Scheduler instead of systemd.</p>

        <h3>1. Store the credentials securely (as Administrator)</h3>
        @verbatim
        <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;">New-Item -ItemType Directory -Force -Path 'C:\ProgramData\ServerDashboard' | Out-Null
@'
DASHBOARD_ENDPOINT="https://your-dashboard-domain/ingest/other-server/<slug>/report"
DASHBOARD_TOKEN="<paste the one-time token here>"
'@ | Set-Content -Path 'C:\ProgramData\ServerDashboard\agent.env' -Encoding utf8

# Restrict the file to Administrators and SYSTEM only
icacls 'C:\ProgramData\ServerDashboard\agent.env' /inheritance:r | Out-Null
icacls 'C:\ProgramData\ServerDashboard\agent.env' /grant:r 'SYSTEM:(R)' 'BUILTIN\Administrators:(F)' | Out-Null</textarea>
        @endverbatim
        <p class="muted">Use the exact <strong>Endpoint</strong> and <strong>Token</strong> shown once above. The scheduled task in step 3 runs as <code>SYSTEM</code>, which is granted read access; no other account can read the token.</p>

        <h3>2. Install the agent script</h3>
        @verbatim
        <textarea readonly rows="46" style="width:100%;font-family:monospace;font-size:12px;">@'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ErrorActionPreference = "Stop"

$ConfigFile = "C:\ProgramData\ServerDashboard\agent.env"
if (-not (Test-Path $ConfigFile)) { Write-Error "Missing $ConfigFile"; exit 1 }

$config = @{}
Get-Content $ConfigFile | ForEach-Object {
    if ($_ -match "^\s*([A-Z_]+)\s*=\s*`"?([^`"]*)`"?\s*$") { $config[$matches[1]] = $matches[2] }
}
$DashboardEndpoint = $config["DASHBOARD_ENDPOINT"]
$DashboardToken = $config["DASHBOARD_TOKEN"]
if (-not $DashboardEndpoint) { Write-Error "DASHBOARD_ENDPOINT not set"; exit 1 }
if (-not $DashboardToken) { Write-Error "DASHBOARD_TOKEN not set"; exit 1 }
if ($DashboardEndpoint -notmatch '^https://') { Write-Error "DASHBOARD_ENDPOINT must use https://"; exit 1 }

$total = 0
$security = 0
$updateDetails = ""
$securityUpdateDetails = ""
try {
    $updateSession = New-Object -ComObject Microsoft.Update.Session
    $updateSearcher = $updateSession.CreateUpdateSearcher()
    $searchResult = $updateSearcher.Search("IsInstalled=0 and IsHidden=0")
    $total = $searchResult.Updates.Count
    $securityUpdates = @()
    foreach ($update in $searchResult.Updates) {
        foreach ($category in $update.Categories) {
            if ($category.Name -eq "Security Updates") { $security++; $securityUpdates += $update; break }
        }
    }
    $updateDetails = (($searchResult.Updates | Select-Object -First 50 | ForEach-Object { $_.Title }) -join "`n")
    $securityUpdateDetails = (($securityUpdates | Select-Object -First 50 | ForEach-Object { $_.Title }) -join "`n")
} catch {
    # Leave counts at 0 if the Windows Update Agent API is unavailable/blocked
}

$rebootRequired = $false
$rebootPaths = @(
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending",
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired",
    "HKLM:\SOFTWARE\Microsoft\Updates\UpdateExeVolatile"
)
foreach ($path in $rebootPaths) { if (Test-Path $path) { $rebootRequired = $true; break } }

$osName = (Get-CimInstance Win32_OperatingSystem).Caption

$phpVersion = ""
$phpUpdateAvailable = $false
if (Get-Command php -ErrorAction SilentlyContinue) {
    $verOutput = & php -v 2>$null | Select-Object -First 1
    if ($verOutput -match "(\d+\.\d+\.\d+)") { $phpVersion = $matches[1] }
}

$checkedAt = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")

$bodyObject = [ordered]@{
    osName = $osName
    totalUpdates = $total
    securityUpdates = $security
    updateDetails = $updateDetails
    securityUpdateDetails = $securityUpdateDetails
    rebootRequired = $rebootRequired
    phpVersion = $phpVersion
    phpUpdateAvailable = $phpUpdateAvailable
    checkedAt = $checkedAt
}
$body = $bodyObject | ConvertTo-Json -Compress

$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$nonce = -join ((1..32) | ForEach-Object { "{0:x}" -f (Get-Random -Maximum 16) })

$hmac = New-Object System.Security.Cryptography.HMACSHA256
$hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($DashboardToken)
$signingInput = "$timestamp.$nonce.$body"
$hashBytes = $hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($signingInput))
$signature = ($hashBytes | ForEach-Object { $_.ToString("x2") }) -join ""

$bodyBytes = [System.Text.Encoding]::UTF8.GetBytes($body)
$headers = @{
    "X-Server-Monitor-Timestamp" = "$timestamp"
    "X-Server-Monitor-Nonce" = $nonce
    "X-Server-Monitor-Signature" = $signature
}

Invoke-RestMethod -Uri $DashboardEndpoint -Method Post -Headers $headers -Body $bodyBytes -ContentType "application/json"
'@ | Set-Content -Path 'C:\ProgramData\ServerDashboard\agent.ps1' -Encoding utf8

icacls 'C:\ProgramData\ServerDashboard\agent.ps1' /inheritance:r | Out-Null
icacls 'C:\ProgramData\ServerDashboard\agent.ps1' /grant:r 'SYSTEM:(RX)' 'BUILTIN\Administrators:(F)' | Out-Null</textarea>
        @endverbatim
        <p class="muted">Uses the built-in Windows Update Agent COM API &mdash; no extra modules (like PSWindowsUpdate) need to be installed. If PHP isn't on the system <code>PATH</code>, <code>phpVersion</code> is simply left blank (nullable server-side), same as the Linux agent.</p>

        <h3>3. Run it on a schedule with Task Scheduler</h3>
        @verbatim
        <textarea readonly rows="16" style="width:100%;font-family:monospace;font-size:12px;">$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -File "C:\ProgramData\ServerDashboard\agent.ps1"'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Hours 3) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopOnIdleEnd

Register-ScheduledTask -TaskName 'ServerDashboard Agent' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force

# Run it once immediately to verify it works
Start-ScheduledTask -TaskName 'ServerDashboard Agent'
Start-Sleep -Seconds 5
Get-ScheduledTaskInfo -TaskName 'ServerDashboard Agent'</textarea>
        @endverbatim
        <p class="muted">Runs as the built-in <code>SYSTEM</code> account (needed to reliably query all pending updates via the Windows Update Agent API) every 3 hours. Check <code>Event Viewer &rarr; Windows Logs &rarr; System</code> or re-run <code>Start-ScheduledTask</code> manually if a report doesn't appear.</p>

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

    <script>
        document.querySelectorAll('[data-open-updates]').forEach((btn) => {
            btn.addEventListener('click', () => document.getElementById(btn.dataset.openUpdates)?.showModal());
        });
        document.querySelectorAll('[data-close-updates]').forEach((btn) => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
        document.querySelectorAll('[data-open-security]').forEach((btn) => {
            btn.addEventListener('click', () => document.getElementById(btn.dataset.openSecurity)?.showModal());
        });
        document.querySelectorAll('[data-close-security]').forEach((btn) => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
    </script>
@endsection
