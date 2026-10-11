@extends('layouts.app')

@section('content')
<style>
    .report-page { --report-red:#e2173d; --report-magenta:#c81e78; --report-purple:#7b2ff7; }
    .report-heading { padding:26px 28px; border-top:5px solid transparent; border-image:linear-gradient(90deg,var(--report-red),var(--report-magenta),var(--report-purple)) 1; }
    .report-heading h1 { font-size:25px; margin:0; }
    .report-heading p { margin:6px 0 0; }
    .report-status { margin:14px 0; padding:14px 18px; border-left:5px solid var(--report-magenta); background:#fff; border-radius:4px; }
    .report-status strong { display:block; margin-bottom:3px; }
    .report-metrics { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:0; margin:14px 0 22px; background:#fff; border:1px solid #e9e5f2; border-radius:8px; overflow:hidden; }
    .report-metric { padding:15px 18px; border-right:1px solid #e9e5f2; border-top:3px solid var(--report-magenta); }
    .report-metric:last-child { border-right:0; }
    .report-metric span { display:block; color:#736c88; font-size:11px; font-weight:700; text-transform:uppercase; }
    .report-metric strong { display:block; margin-top:5px; font-size:24px; line-height:1.2; }
    .report-metric small { display:block; margin-top:3px; color:#736c88; }
    .report-section { margin:0 0 16px; padding:20px 22px; }
    .report-section h2 { margin:0 0 12px; font-size:16px; }
    .report-section h3 { margin:18px 0 8px; }
    .report-section p:last-child { margin-bottom:0; }
    .report-table { border:1px solid #eadde7; border-radius:6px; overflow:hidden; }
    .report-table table { min-width:580px; }
    .report-table th { color:#fff; background:linear-gradient(100deg,#c81e78,#7b2ff7); font-size:11px; }
    .report-table td { font-size:13px; }
    .report-table tbody tr:nth-child(even) { background:#fbf5fa; }
    .report-table tbody tr:hover { background:#f6e8f1; }
    .report-table tbody tr:last-child td { border-bottom:0; }
    .report-tag { display:inline-block; padding:4px 8px; border-radius:4px; color:#86144e; background:#f8e7f0; font-size:11px; font-weight:700; white-space:nowrap; }
    .report-note { padding:10px 12px; color:#62546b; background:#faf7fb; border-left:3px solid #c81e78; font-size:12px; }
    .report-list { margin:0; padding-left:19px; }
    .report-list li { margin:6px 0; }
    .report-details { border-top:1px solid #e9e5f2; }
    .report-details summary { padding:15px 4px; color:#8d185d; font-weight:700; cursor:pointer; }
    .report-details[open] summary { border-bottom:1px solid #e9e5f2; margin-bottom:14px; }
    .report-details .report-section { margin-top:0; }
    .report-footnote { margin:20px 0; color:#736c88; font-size:12px; line-height:1.5; }
    @media(max-width:900px) { .report-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); } .report-metric { border-bottom:1px solid #e9e5f2; } }
    @media(max-width:600px) { .report-heading { padding:20px 17px; } .report-section { padding:16px; } .report-metrics { grid-template-columns:1fr 1fr; } .report-metric { padding:12px; } .report-metric strong { font-size:20px; } }
</style>

<div class="report-page">
    <header class="panel report-heading">
        <h1>IT &amp; Security Report</h1>
        <p class="muted">{{ $report['period']['label'] }} <span aria-hidden="true">·</span> Prepared {{ now()->format('j M Y, H:i T') }}</p>
    </header>

    @php
        $hasUrgentRisk = collect($report['risks'])->contains(fn ($risk) => str_contains(strtolower($risk), 'security updates') || str_contains(strtolower($risk), 'below the 99.9%'));
        $uptimeLabel = $report['uptime']['percent'] === null ? 'Not measured' : number_format($report['uptime']['percent'], 3).'%';
    @endphp
    <div class="report-status">
        <strong>{{ $hasUrgentRisk ? 'Attention required' : 'Monthly overview' }}</strong>
        <span>{{ $report['ec2']['security_pending'] }} server security update(s) pending. {{ $report['vulnerabilities']['plugin_findings']->count() + $report['vulnerabilities']['core_findings']->count() }} WordPress update finding(s). Observed availability: {{ $uptimeLabel }}.</span>
    </div>

    <section class="report-metrics" aria-label="Monthly summary">
        <div class="report-metric"><span>EC2 instances</span><strong>{{ $report['ec2']['count'] }}</strong><small>Across enabled regions</small></div>
        <div class="report-metric"><span>Pending server updates</span><strong>{{ $report['ec2']['patches_pending'] }}</strong><small>{{ $report['ec2']['security_pending'] }} security-related</small></div>
        <div class="report-metric"><span>WordPress sites</span><strong>{{ $report['sites']->count() }}</strong><small>Registered sites</small></div>
        <div class="report-metric"><span>Availability</span><strong>{{ $uptimeLabel }}</strong><small>Target 99.9%</small></div>
        <div class="report-metric"><span>AWS cost</span><strong>{{ $report['costs']['available'] ? number_format($report['costs']['total'], 2) : 'Unavailable' }}</strong><small>{{ $report['costs']['available'] ? $report['costs']['unit'].' for the month' : 'Cost Explorer access required' }}</small></div>
    </section>

    <section class="panel report-section">
        <h2>What needs attention</h2>
        <ul class="report-list">@foreach ($report['risks'] as $risk)<li>{{ $risk }}</li>@endforeach</ul>
    </section>

    <section class="panel report-section">
        <h2>Server patch status</h2>
        <div class="scroll report-table"><table><thead><tr><th>Server</th><th>Updates pending</th><th>Security updates</th><th>Last report</th></tr></thead><tbody>
            @forelse ($report['ec2']['latest'] as $instance)
                <tr><td><strong>{{ $instance->instance_name }}</strong><br><small>{{ $instance->instance_id }}{{ $instance->aws_region ? ' · '.$instance->aws_region : '' }}</small></td><td>{{ $instance->missing_count }}</td><td>{{ $instance->security_count }}</td><td>{{ $instance->checked_at?->format('j M Y, H:i') ?? 'Unknown' }}</td></tr>
            @empty
                <tr><td colspan="4">No EC2 patch snapshots yet. Check AWS/SSM access and scheduled collection.</td></tr>
            @endforelse
            @foreach ($report['servers'] as $server)
                <tr><td><strong>{{ $server->name }}</strong><br><small>Agent-reported server</small></td><td>{{ $server->total_updates ?? 'Unknown' }}</td><td>{{ $server->security_updates ?? 'Unknown' }}</td><td>{{ $server->last_reported_at?->format('j M Y, H:i') ?? 'Never' }}</td></tr>
            @endforeach
        </tbody></table></div>
    </section>

    <section class="panel report-section">
        <h2>WordPress updates</h2>
        <p>{{ $report['vulnerabilities']['plugin_findings']->count() }} outdated plugin finding(s), {{ $report['vulnerabilities']['core_findings']->count() }} outdated core finding(s); {{ $report['vulnerabilities']['resolved_update_findings'] }} update finding(s) cleared in later reports.</p>
        <p class="report-note">These are available-update findings, not results from a CVE scanner. No vulnerability scanner is integrated, and cleared findings are inferred from later reports rather than verified installation records. EC2 missing-patch counts decreased by an estimated {{ $report['vulnerabilities']['patches_fixed_estimate'] }} during the month.</p>
        @if ($report['vulnerabilities']['plugin_findings']->isNotEmpty() || $report['vulnerabilities']['core_findings']->isNotEmpty())
            <div class="scroll report-table" style="margin-top:12px"><table><thead><tr><th>Site</th><th>Component</th><th>Installed</th><th>Available</th></tr></thead><tbody>
                @foreach ($report['vulnerabilities']['plugin_findings']->take(12) as $finding)<tr><td>{{ $finding->site_name }}</td><td>{{ $finding->plugin_name }}</td><td>{{ $finding->current_version }}</td><td>{{ $finding->latest_version }}</td></tr>@endforeach
                @foreach ($report['vulnerabilities']['core_findings']->take(12) as $finding)<tr><td>{{ $finding->site_name }}</td><td>WordPress core</td><td>{{ $finding->current_version }}</td><td>{{ $finding->latest_version }}</td></tr>@endforeach
            </tbody></table></div>
        @else
            <p class="muted">No outdated WordPress plugin or core reports were recorded for this month.</p>
        @endif
    </section>

    <section class="panel report-section">
        <h2>Availability</h2>
        <p>{{ $report['uptime']['percent'] === null ? 'No uptime observations are available for this month.' : number_format($report['uptime']['percent'], 3).'% observed availability across '.number_format($report['uptime']['checks']->count()).' checks.' }} Target: 99.9%.</p>
        <p class="report-note">Checks run every five minutes. This is sampled availability, not continuous monitoring; periods without probe data are excluded.</p>
        <div class="scroll report-table" style="margin-top:12px"><table><thead><tr><th>Asset</th><th>Type</th><th>Availability</th><th>Checks</th></tr></thead><tbody>
            @forelse ($report['uptime']['assets'] as $asset)<tr><td>{{ $asset['name'] }}</td><td><span class="report-tag">{{ strtoupper($asset['type']) }}</span></td><td>{{ number_format($asset['percent'], 3) }}%</td><td>{{ number_format($asset['checks']) }}</td></tr>@empty<tr><td colspan="4">Per-asset results will appear after scheduled checks have run.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <details class="report-details">
        <summary>AWS costs and backup policy</summary>
        <section class="panel report-section">
            <h2>AWS cost breakdown</h2>
            @if ($report['costs']['available'])
                <div class="scroll report-table"><table><thead><tr><th>AWS service</th><th>Monthly cost</th></tr></thead><tbody>@forelse ($report['costs']['groups']->take(10) as $cost)<tr><td>{{ $cost['service'] }}</td><td>{{ number_format($cost['amount'], 2) }} {{ $cost['unit'] }}</td></tr>@empty<tr><td colspan="2">No service costs returned.</td></tr>@endforelse</tbody></table></div>
            @else
                <p>Cost data unavailable. Grant Cost Explorer access to the dashboard's AWS identity.</p>
            @endif
            <h3>Backup policy</h3>
            <div class="scroll report-table"><table><thead><tr><th>Systems</th><th>Reported schedule</th><th>Restore test</th></tr></thead><tbody>@foreach ($report['backups'] as $backup)<tr><td>{{ $backup['asset'] }}</td><td>{{ $backup['policy'] }}</td><td>{{ $backup['restore_test'] }}</td></tr>@endforeach</tbody></table></div>
            <p class="report-note" style="margin-top:10px">Backup schedules are reported policy only and are not verified against AWS or WP Engine. Review backup jobs monthly and test one restore in an isolated environment each quarter.</p>
        </section>
    </details>

    <details class="report-details">
        <summary>Config App change log</summary>
        <section class="panel report-section">
            <h2>Support ticket changes</h2>
            <div class="scroll report-table"><table><thead><tr><th>Date</th><th>Ticket</th><th>Changed by</th><th>What changed</th></tr></thead><tbody>
                @forelse ($report['ticket_changes'] as $change)
                    <tr><td>{{ $change->created_at->format('j M Y, H:i') }}</td><td>{{ $change->ticket?->title ?? 'Deleted ticket' }}</td><td>{{ $change->actor?->name ?? 'Former user' }}</td><td>@forelse ($change->changes as $field => $values)<strong>{{ ucfirst(str_replace('_', ' ', $field)) }}:</strong> {{ \Illuminate\Support\Str::limit((string) ($values['before'] ?? '—'), 70) }} → {{ \Illuminate\Support\Str::limit((string) ($values['after'] ?? '—'), 70) }}<br>@empty Ticket {{ $change->action }} @endforelse</td></tr>
                @empty
                    <tr><td colspan="4">No ticket changes were recorded this month.</td></tr>
                @endforelse
            </tbody></table></div>
            <p class="report-note" style="margin-top:10px">Detailed change history starts from the date this feature was deployed; older edits cannot be reconstructed.</p>
        </section>
    </details>

    <details class="report-details">
        <summary>Systems, access and operating guide</summary>
        <section class="panel report-section">
            <h2>Dashboard access</h2>
            <div class="scroll report-table"><table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Access</th><th>MFA</th></tr></thead><tbody>@forelse ($report['users'] as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ ucfirst($user->role) }}</td><td>{{ $user->role === 'admin' ? 'Manage dashboard, assets, settings and users' : 'View monitoring dashboard' }}</td><td>{{ $user->two_factor_confirmed_at ? 'Enabled' : 'Not enrolled' }}</td></tr>@empty<tr><td colspan="5">No dashboard users.</td></tr>@endforelse</tbody></table></div>
            <h3>WordPress site inventory</h3>
            <div class="scroll report-table"><table><thead><tr><th>Site</th><th>URL</th><th>Monitoring</th><th>Core</th><th>PHP</th></tr></thead><tbody>@forelse ($report['sites'] as $site)<tr><td>{{ $site->name }}</td><td><a href="{{ $site->url }}" rel="noreferrer">{{ $site->url }}</a></td><td>{{ $site->is_active ? 'Active' : 'Disabled' }}</td><td>{{ $site->last_core_status ?? 'No report' }}</td><td>{{ $site->last_php_version ?? 'No report' }}</td></tr>@empty<tr><td colspan="5">No WordPress sites registered.</td></tr>@endforelse</tbody></table></div>
            <h3>EC2 inventory</h3>
            <div class="scroll report-table"><table><thead><tr><th>Instance</th><th>Region</th><th>Availability</th><th>Pending patches</th><th>Security patches</th></tr></thead><tbody>
                @forelse ($report['ec2_inventory'] as $instance)
                    @php($patch = $report['ec2']['latest']->get($instance->asset_key))
                    <tr><td>{{ $instance->asset_name }}<br><small>{{ \Illuminate\Support\Str::after($instance->asset_key, ':') }}</small></td><td>{{ \Illuminate\Support\Str::before($instance->asset_key, ':') }}</td><td>{{ $instance->is_up ? 'Up' : 'Down' }}</td><td>{{ $patch?->missing_count ?? 'No SSM data' }}</td><td>{{ $patch?->security_count ?? 'No SSM data' }}</td></tr>
                @empty
                    <tr><td colspan="5">No EC2 uptime probes recorded yet.</td></tr>
                @endforelse
            </tbody></table></div>
            <h3>Essential tasks</h3>
            <ol class="report-list">
                <li><strong>WordPress:</strong> register the public HTTPS URL, install the reporter plugin, configure its endpoint/token, then send a test report.</li>
                <li><strong>EC2:</strong> review SSM patch status. The admin dashboard's patch action installs updates and may reboot instances; schedule it carefully.</li>
                <li><strong>Support changes:</strong> create or update a Support Ticket for every Config App change; its actor and before/after values are captured here.</li>
                <li><strong>Access:</strong> remove departed users in User Management. Admins can change assets and settings; viewers have read-only monitoring access. MFA is required.</li>
                <li><strong>Monthly review:</strong> verify backups, check AWS costs, choose one security/performance/cost improvement, then record the action and result in Support Ticketing.</li>
                <li><strong>Report sharing:</strong> maintain the recipient in Reporting Settings. This link grants report access; rotate it if disclosed outside the intended audience.</li>
            </ol>
        </section>
    </details>

    <p class="report-footnote">Data is based on reports and checks received by this dashboard. Missing integration data is shown as unavailable, not assumed healthy. The 99.9% target, backup policies and update-fix estimates should be reviewed against provider records.</p>
</div>
@endsection