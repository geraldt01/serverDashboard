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
    .report-table th { color:#fff; background:#c81e78; font-size:11px; }
    .report-table td { font-size:13px; }
    .report-table tbody tr:nth-child(even) { background:#fbf5fa; }
    .report-table tbody tr:hover { background:#f6e8f1; }
    .report-table tbody tr:last-child td { border-bottom:0; }
    .report-pagination { display:flex; align-items:center; justify-content:flex-end; gap:10px; margin-top:10px; color:#736c88; font-size:12px; }
    .report-pagination button { padding:6px 10px; font-size:12px; }
    .report-pagination button:disabled { opacity:.45; cursor:not-allowed; }
    .patch-details-preview { max-width:480px; overflow-wrap:anywhere; }
    .patch-details-more { margin-top:6px; padding:5px 9px; font-size:12px; }
    .patch-details-dialog { width:min(680px,calc(100vw - 32px)); max-height:85vh; box-sizing:border-box; padding:20px; border:0; border-radius:8px; overflow:auto; }
    .patch-details-dialog::backdrop { background:rgba(15,23,42,.45); }
    .patch-details-dialog h3 { margin:0 0 8px; font-size:18px; overflow-wrap:anywhere; }
    .patch-details-dialog .patch-details-text { white-space:pre-wrap; overflow-wrap:anywhere; font-size:13px; line-height:1.6; }
    .patch-details-actions { display:flex; justify-content:flex-end; margin-top:16px; }
    .report-tag { display:inline-block; padding:4px 8px; border-radius:4px; color:#86144e; background:#f8e7f0; font-size:11px; font-weight:700; white-space:nowrap; }
    .inspector-coverage { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); margin:14px 0 18px; background:#fff; border:1px solid #e9e5f2; border-radius:8px; overflow:hidden; }
    .inspector-coverage-item { padding:15px 18px; border-top:3px solid #0875e1; }
    .inspector-coverage-item + .inspector-coverage-item { border-left:1px solid #e9e5f2; }
    .inspector-coverage-item strong { display:block; font-size:13px; }
    .inspector-coverage-value { display:block; margin-top:4px; color:#0875e1; font-size:36px; font-weight:300; line-height:1.15; }
    .inspector-coverage-total { color:#202633; font-size:12px; }
    .inspector-coverage-note { display:block; margin-top:5px; color:#736c88; font-size:11px; }
    .inspector-coverage-link { display:block; margin:4px 0 0; padding:0; border:0; color:#0875e1; background:transparent; font-size:36px; font-weight:300; line-height:1.15; cursor:pointer; text-align:left; }
    .inspector-coverage-link:hover { text-decoration:underline; }
    .inspector-coverage-link:focus-visible { outline:2px solid #0875e1; outline-offset:2px; }
    .inspector-coverage-link span { color:#202633; font-size:12px; font-weight:400; }
    .inspector-machine-dialog { width:min(1200px,calc(100vw - 32px)); }
    .inspector-machine-search { display:block; width:100%; box-sizing:border-box; margin:12px 0; padding:9px 11px; border:1px solid #9ba3b2; border-radius:3px; font:inherit; }
    .inspector-machine-dialog .report-table table { min-width:900px; }
    .inspector-machine-empty { padding:12px; color:#736c88; }
    .inspector-machine-select { cursor:pointer; }
    .inspector-machine-select:hover, .inspector-machine-select:focus { outline:2px solid #0875e1; outline-offset:-2px; }
    .inspector-machine-filter-status { margin:8px 0; color:#62546b; font-size:12px; }
    .inspector-machine-filter-status button { margin-left:8px; padding:3px 7px; font-size:11px; }
    .inspector-finding-row { cursor:pointer; }
    .inspector-finding-row:focus-visible { outline:2px solid #0875e1; outline-offset:-2px; }
    .inspector-finding-dialog { width:min(900px,calc(100vw - 32px)); }
    .inspector-detail-section { margin-top:18px; }
    .inspector-detail-section h4 { margin:0 0 8px; padding:7px 9px; background:#eeeef1; font-size:13px; }
    .inspector-detail-section p { line-height:1.5; overflow-wrap:anywhere; }
    .inspector-detail-meta { display:grid; grid-template-columns:minmax(130px, .8fr) 2fr; margin:0; }
    .inspector-detail-meta dt, .inspector-detail-meta dd { margin:0; padding:7px 9px; border-bottom:1px solid #e5e3e9; overflow-wrap:anywhere; }
    .inspector-detail-meta dt { font-weight:700; }
    .inspector-detail-dialog table { width:100%; border-collapse:collapse; }
    .inspector-detail-dialog th, .inspector-detail-dialog td { padding:7px 9px; border-bottom:1px solid #e5e3e9; text-align:left; vertical-align:top; overflow-wrap:anywhere; }
    @media(max-width:600px) { .inspector-detail-meta { grid-template-columns:1fr; } .inspector-detail-meta dt { border-bottom:0; padding-bottom:2px; } .inspector-detail-meta dd { padding-top:2px; } }
    .report-tags { display:flex; flex-wrap:wrap; gap:7px; margin:10px 0 12px; }
    .report-tags .report-tag { cursor:pointer; }
    .report-tags .report-tag[aria-pressed="true"] { color:#fff; background:#0875e1; border-color:#0875e1; }
    .report-tags .report-tag:focus-visible { outline:2px solid #0875e1; outline-offset:2px; }
    .inspector-filter-empty { margin:8px 0; color:#736c88; }
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
    @media(max-width:600px) { .inspector-coverage-item { padding:12px; } .inspector-coverage-value { font-size:30px; } }
</style>

<div class="report-page">
    <header class="panel report-heading">
        <h1>IT &amp; Security Report</h1>
        <p class="muted">{{ $report['period']['label'] }} <span aria-hidden="true">·</span> Prepared {{ now()->format('j M Y, H:i T') }}</p>
    </header>

    @php
        $hasUrgentRisk = collect($report['risks'])->contains(fn ($risk) => str_contains(strtolower($risk), 'security updates') || str_contains(strtolower($risk), 'below the 99.9%') || str_contains(strtolower($risk), 'amazon inspector reports'));
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
        <div class="report-metric"><span>AWS cost</span><strong>{{ $report['costs']['available'] ? number_format($report['costs']['total'], 2) : 'Unavailable' }}</strong><small>{{ $report['costs']['available'] ? $report['costs']['unit'].' · 6 months + current' : 'Cost Explorer access required' }}</small></div>
    </section>

    <section class="panel report-section">
        <h2>What needs attention</h2>
        <ul class="report-list">@foreach ($report['risks'] as $risk)<li>{{ $risk }}</li>@endforeach</ul>
    </section>

    <section class="panel report-section">
        <h2>Amazon Inspector vulnerabilities</h2>
        @if ($report['inspector']['available'])
            <p>Active vulnerability findings returned from Inspector in {{ count($report['inspector']['regions']) }} queried region(s).</p>
            @php($packageCoverage = $report['inspector']['package_coverage'])
            <div class="inspector-coverage" aria-label="Inspector findings with exploit and fix availability">
                <div class="inspector-coverage-item">
                    <strong>Findings with public exploit available</strong>
                    <span class="inspector-coverage-value">{{ number_format($packageCoverage['exploit']['YES']) }}<span class="inspector-coverage-total"> / {{ number_format($packageCoverage['total']) }} total package findings</span></span>
                    <span class="inspector-coverage-note">{{ number_format($packageCoverage['exploit']['PARTIAL']) }} partial; {{ number_format($packageCoverage['exploit']['NOT_REPORTED']) }} not reported by Inspector</span>
                </div>
                <div class="inspector-coverage-item">
                    <strong>Findings with fix available</strong>
                    <button type="button" class="inspector-coverage-link" data-open-inspector-machines aria-haspopup="dialog" aria-controls="inspector-fix-machines-dialog">
                        {{ number_format($packageCoverage['fix']['YES']) }}<span> / {{ number_format($packageCoverage['total']) }} total package findings</span>
                    </button>
                    <span class="inspector-coverage-note">{{ number_format($packageCoverage['fix']['PARTIAL']) }} partial; {{ number_format($packageCoverage['fix']['NOT_REPORTED']) }} not reported by Inspector</span>
                </div>
            </div>
            <dialog id="inspector-fix-machines-dialog" class="patch-details-dialog inspector-machine-dialog" aria-labelledby="inspector-machines-title">
                <h3 id="inspector-machines-title">Virtual machines with fixable Inspector findings</h3>
                <p class="muted">{{ count($report['inspector']['fix_available_machines']) }} active virtual machine(s) have at least one package vulnerability finding with a fix available.</p>
                <label for="inspector-machine-search">Filter virtual machines</label>
                <input id="inspector-machine-search" class="inspector-machine-search" type="search" placeholder="Search by instance ID, name, OS, image, account, or region" autocomplete="off">
                <div class="scroll report-table">
                    <table><thead><tr><th>Virtual machine</th><th>Operating system</th><th>Machine image</th><th>Account</th><th>Cloud provider</th><th>Region</th><th>Critical</th><th>High</th><th>All</th></tr></thead><tbody>
                        @forelse ($report['inspector']['fix_available_machines'] as $machine)
                            <tr data-inspector-machine-row data-inspector-machine-select data-machine-key="{{ $machine['key'] }}" data-machine-name="{{ $machine['name'] }}" tabindex="0" role="button" aria-label="Show Inspector findings for {{ $machine['name'] }}">
                                <td><strong>{{ $machine['name'] }}</strong><br><small>{{ $machine['id'] }}</small></td>
                                <td>{{ $machine['operating_system'] }}</td>
                                <td>{{ $machine['image_id'] }}</td>
                                <td>{{ $machine['account'] }}</td>
                                <td>{{ $machine['cloud_provider'] }}</td>
                                <td>{{ $machine['region'] }}</td>
                                <td>{{ number_format($machine['critical']) }}</td>
                                <td>{{ number_format($machine['high']) }}</td>
                                <td>{{ number_format($machine['all']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9">No affected EC2 virtual machines were returned for active findings with a fix available.</td></tr>
                        @endforelse
                    </tbody></table>
                </div>
                <p class="inspector-machine-empty" data-inspector-machine-empty hidden>No virtual machines match that search.</p>
                <div class="patch-details-actions"><button type="button" data-close-inspector-machines autofocus>Close</button></div>
            </dialog>
            <p>{{ number_format($report['inspector']['total']) }} active package and code vulnerability finding(s) across {{ count($report['inspector']['regions']) }} queried region(s). Select a severity to filter the findings table.</p>
            <p class="inspector-machine-filter-status" data-inspector-machine-filter-status hidden>Showing findings for <strong data-inspector-selected-machine-name></strong><button type="button" data-clear-inspector-machine>Clear machine filter</button></p>
            <div class="report-tags" role="group" aria-label="Filter Inspector findings by severity">
                <button type="button" class="report-tag" data-inspector-severity="ALL" aria-pressed="true">All {{ number_format($report['inspector']['total']) }}</button>
                @foreach ($report['inspector']['counts'] as $severity => $count)
                    <button type="button" class="report-tag" data-inspector-severity="{{ $severity }}" aria-pressed="false">{{ $severity }} {{ number_format($count) }}</button>
                @endforeach
            </div>
            @if ($report['inspector']['findings'])
                <div class="scroll report-table" data-report-pagination data-page-size="10" data-filter-buttons="[data-inspector-severity]" data-machine-filter-table><table><thead><tr><th>Severity</th><th>Finding / CVE</th><th>Resource</th><th>Package / code location</th><th>Fix available</th><th>Age</th><th>First observed at</th></tr></thead><tbody>
                    @foreach ($report['inspector']['findings'] as $finding)
                        <tr class="inspector-finding-row" data-severity="{{ $finding['severity'] }}" data-machine-keys="{{ implode('~', $finding['machine_keys']) }}" data-inspector-finding-index="{{ $loop->index }}" tabindex="0" role="button" aria-haspopup="dialog" aria-label="View details for {{ $finding['title'] }}">
                            <td><span class="report-tag">{{ $finding['severity'] }}</span></td>
                            <td>{{ $finding['title'] }}<br><small>{{ $finding['vulnerability_id'] }}</small></td>
                            <td>{{ $finding['resource'] }}<br><small>{{ $finding['resource_type'] }}</small></td>
                            <td>{{ $finding['packages'] ?: 'Not provided' }}</td>
                            <td>{{ $finding['fix_available'] }}{{ $finding['fixed_versions'] ? ' · '.$finding['fixed_versions'] : '' }}</td>
                            <td>{{ ($finding['age_days'] ?? null) === null ? 'Unknown' : number_format($finding['age_days']).' days' }}</td>
                            <td>{{ ($finding['first_observed_at'] ?? null)?->format('j M Y, H:i T') ?? 'Unknown' }}</td>
                        </tr>
                    @endforeach
                </tbody></table></div>
                <div class="report-pagination" data-pagination-controls aria-label="Amazon Inspector findings pagination"><button type="button" data-page-previous aria-label="Previous Inspector findings page">Previous</button><span data-page-status></span><button type="button" data-page-next aria-label="Next Inspector findings page">Next</button></div>
                <p class="inspector-filter-empty" data-inspector-filter-empty hidden>No findings match this severity.</p>
                <dialog id="inspector-finding-dialog" class="patch-details-dialog inspector-finding-dialog inspector-detail-dialog" aria-labelledby="inspector-finding-title">
                    <h3 id="inspector-finding-title"></h3>
                    <p class="muted" data-inspector-finding-subtitle></p>
                    <div class="inspector-detail-section">
                        <h4>Finding overview</h4>
                        <dl class="inspector-detail-meta">
                            <dt>Severity</dt><dd data-inspector-detail="severity"></dd>
                            <dt>Type</dt><dd data-inspector-detail="type"></dd>
                            <dt>Resource</dt><dd data-inspector-detail="resource"></dd>
                            <dt>Account</dt><dd data-inspector-detail="account"></dd>
                            <dt>Cloud provider</dt><dd data-inspector-detail="cloud_provider"></dd>
                            <dt>Region</dt><dd data-inspector-detail="region"></dd>
                            <dt>Fix available</dt><dd data-inspector-detail="fix_available"></dd>
                            <dt>Public exploit available</dt><dd data-inspector-detail="exploit_available"></dd>
                            <dt>Inspector score</dt><dd data-inspector-detail="inspector_score"></dd>
                            <dt>First observed at</dt><dd data-inspector-detail="first_observed_at"></dd>
                            <dt>Age</dt><dd data-inspector-detail="age"></dd>
                        </dl>
                    </div>
                    <div class="inspector-detail-section" data-inspector-description-section>
                        <h4>Finding description</h4>
                        <p data-inspector-detail="description"></p>
                    </div>
                    <div class="inspector-detail-section" data-inspector-remediation-section>
                        <h4>Remediation</h4>
                        <p data-inspector-detail="remediation"></p>
                        <a data-inspector-remediation-link target="_blank" rel="noopener noreferrer" hidden>Open remediation link</a>
                    </div>
                    <div class="inspector-detail-section" data-inspector-vulnerability-section>
                        <h4>Vulnerability details</h4>
                        <dl class="inspector-detail-meta">
                            <dt>Vulnerability ID</dt><dd data-inspector-detail="vulnerability_id"></dd>
                            <dt>Vulnerability source</dt><dd data-inspector-detail="vulnerability_source"></dd>
                            <dt>Related vulnerabilities</dt><dd data-inspector-detail="related_vulnerabilities"></dd>
                            <dt>Code weakness</dt><dd data-inspector-detail="cwes"></dd>
                        </dl>
                        <div data-inspector-reference-links></div>
                    </div>
                    <div class="inspector-detail-section" data-inspector-cvss-section>
                        <h4>CVSS scores</h4>
                        <div class="scroll"><table><thead><tr><th>Score</th><th>Version</th><th>Source</th><th>Scoring vector</th></tr></thead><tbody data-inspector-cvss-rows></tbody></table></div>
                    </div>
                    <div class="inspector-detail-section" data-inspector-packages-section>
                        <h4>Vulnerable packages</h4>
                        <div class="scroll"><table><thead><tr><th>Package</th><th>Installed version</th><th>Fixed version</th><th>Package manager</th><th>File path</th></tr></thead><tbody data-inspector-package-rows></tbody></table></div>
                    </div>
                    <div class="patch-details-actions"><button type="button" data-close-inspector-finding autofocus>Close</button></div>
                </dialog>
            @else
                <p class="muted">No active package or code vulnerability findings were returned for the configured regions.</p>
            @endif
        @else
            <p>{{ $report['inspector']['data_note'] }}</p>
        @endif
        <p class="report-note">{{ $report['inspector']['data_note'] }} Checked {{ $report['inspector']['refreshed_at']->format('j M Y, H:i T') }}. Active package and code vulnerability findings are included; network-reachability findings are outside this section.</p>
    </section>

    <section class="panel report-section">
        <h2>Server patch status</h2>
        <h3>Current Status</h3>
        <div class="scroll report-table"><table><thead><tr><th>Server</th><th>Updates pending</th><th>Security updates</th><th>Pending update details</th><th>Pending security update details</th><th>Last report</th></tr></thead><tbody>
            @forelse ($report['ec2']['latest'] as $instance)
                <tr><td><strong>{{ $instance->instance_name }}</strong><br><small>{{ $instance->instance_id }}{{ $instance->aws_region ? ' · '.$instance->aws_region : '' }}</small></td><td>{{ $instance->missing_count }}</td><td>{{ $instance->security_count }}</td><td colspan="2">Individual package details are not reported by SSM.</td><td>{{ $instance->checked_at?->format('j M Y, H:i') ?? 'Unknown' }}</td></tr>
            @empty
                <tr><td colspan="6">No EC2 patch snapshots yet. Check AWS/SSM access and scheduled collection.</td></tr>
            @endforelse
            @foreach ($report['servers'] as $server)
                <tr><td><strong>{{ $server->name }}</strong><br><small>Agent-reported server</small></td><td>{{ $server->total_updates ?? 'Unknown' }}</td><td>{{ $server->security_updates ?? 'Unknown' }}</td><td style="white-space:pre-line">{{ $server->update_details ?: 'Not reported' }}</td><td style="white-space:pre-line">{{ $server->security_update_details ?: 'Not reported' }}</td><td>{{ $server->last_reported_at?->format('j M Y, H:i') ?? 'Never' }}</td></tr>
            @endforeach
        </tbody></table></div>
        <h3 style="margin-top:22px">Recent patch history · last 30 days</h3>
        <div class="scroll report-table" data-report-pagination data-page-size="5"><table><thead><tr><th>Fix observed at</th><th>Server</th><th>Source</th><th>Resolved patches and update details</th></tr></thead><tbody>
            @forelse ($report['patch_history'] as $event)
                <tr>
                    <td>{{ $event['at']->format('j M Y, H:i:s T') }}</td>
                    <td>{{ $event['server'] }}@if ($event['region'])<br><small>{{ $event['region'] }}</small>@endif</td>
                    <td><span class="report-tag">{{ $event['source'] }}</span></td>
                    <td>
                        <div class="patch-details-preview">{{ \Illuminate\Support\Str::limit($event['summary'], 110) }}</div>
                        @if ($event['details'])<small class="patch-details-preview" style="display:block">{{ \Illuminate\Support\Str::limit($event['details'], 100) }}</small>@endif
                        <button type="button" class="patch-details-more" data-open-patch-details="patch-details-{{ $loop->index }}" aria-haspopup="dialog" aria-controls="patch-details-{{ $loop->index }}">View more</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">No patch fixes have been observed in the last 30 days.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="report-pagination" data-pagination-controls aria-label="Server patch history pagination"><button type="button" data-page-previous aria-label="Previous server patch history page">Previous</button><span data-page-status></span><button type="button" data-page-next aria-label="Next server patch history page">Next</button></div>
        @foreach ($report['patch_history'] as $event)
            <dialog id="patch-details-{{ $loop->index }}" class="patch-details-dialog" aria-labelledby="patch-details-title-{{ $loop->index }}">
                <h3 id="patch-details-title-{{ $loop->index }}">{{ $event['server'] }}</h3>
                <p class="muted">{{ $event['at']->format('j M Y, H:i:s T') }} &middot; {{ $event['source'] }}{{ $event['region'] ? ' · '.$event['region'] : '' }}</p>
                <p class="patch-details-text">{{ $event['summary'] }}</p>
                @if ($event['details'])<div class="patch-details-text">{{ $event['details'] }}</div>@endif
                <div class="patch-details-actions"><button type="button" data-close-patch-details autofocus>Close</button></div>
            </dialog>
        @endforeach
        <p class="report-note" style="margin-top:10px">Times show when a report first observed fewer pending patches, not when updates became available. These are inferred fixes, not verified installation timestamps: the change occurred between checks. Package details are shown when a previously reported pending list has fully cleared; partial reductions cannot identify individual installed packages. Initial, unchanged and increased pending counts are excluded.</p>
    </section>

    <section class="panel report-section">
        <h2>WordPress updates</h2>
        <p>{{ $report['vulnerabilities']['plugin_findings']->count() }} outdated plugin finding(s), {{ $report['vulnerabilities']['core_findings']->count() }} outdated core finding(s); {{ $report['vulnerabilities']['resolved_update_findings'] }} update finding(s) cleared in later reports.</p>
        <p class="report-note">These are available-update findings, not confirmed exploitable vulnerabilities. Amazon Inspector results are reported in the separate section above, and cleared WordPress findings are inferred from later reports rather than verified installation records. EC2 missing-patch counts decreased by an estimated {{ $report['vulnerabilities']['patches_fixed_estimate'] }} during the month.</p>
        <h3>Current Status</h3>
        @if ($report['vulnerabilities']['plugin_findings']->isNotEmpty() || $report['vulnerabilities']['core_findings']->isNotEmpty())
            <div class="scroll report-table" style="margin-top:12px"><table><thead><tr><th>Site</th><th>Component</th><th>Installed</th><th>Available</th></tr></thead><tbody>
                @foreach ($report['vulnerabilities']['plugin_findings']->take(12) as $finding)<tr><td>{{ $finding->site_name }}</td><td>{{ $finding->plugin_name }}</td><td>{{ $finding->current_version }}</td><td>{{ $finding->latest_version }}</td></tr>@endforeach
                @foreach ($report['vulnerabilities']['core_findings']->take(12) as $finding)<tr><td>{{ $finding->site_name }}</td><td>WordPress core</td><td>{{ $finding->current_version }}</td><td>{{ $finding->latest_version }}</td></tr>@endforeach
            </tbody></table></div>
        @else
            <p class="muted">No outdated WordPress plugin or core reports were recorded for this month.</p>
        @endif
        <h3 style="margin-top:22px">Recent WordPress update history · last 30 days</h3>
        <div class="scroll report-table" data-report-pagination data-page-size="5"><table><thead><tr><th>Update observed at</th><th>Site</th><th>Type</th><th>Component</th><th>Versions (before → after)</th><th>Update details</th></tr></thead><tbody>
            @forelse ($report['wordpress_update_history'] as $event)
                <tr>
                    <td>{{ $event['at']?->format('j M Y, H:i:s T') ?? 'Unknown' }}</td>
                    <td>{{ $event['site'] }}</td>
                    <td><span class="report-tag">{{ $event['type'] }}</span></td>
                    <td>{{ $event['component'] }}</td>
                    <td>{{ $event['current'] ?: 'Unknown' }}{{ $event['latest'] ? ' → '.$event['latest'] : '' }}</td>
                    <td>{{ $event['details'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No installed WordPress version upgrades have been observed in the last 30 days.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="report-pagination" data-pagination-controls aria-label="WordPress update history pagination"><button type="button" data-page-previous aria-label="Previous WordPress update history page">Previous</button><span data-page-status></span><button type="button" data-page-next aria-label="Next WordPress update history page">Next</button></div>
        <p class="report-note" style="margin-top:10px">Only increases in installed plugin or core versions are shown, not available updates or current-status checks. The timestamp is when the newer installed version was first reported; the update occurred between checks. Exact installation-completion times are not currently reported.</p>
    </section>

    <section class="panel report-section">
        <h2>Availability</h2>
        <p>{{ $report['uptime']['percent'] === null ? 'No uptime observations are available for this month.' : number_format($report['uptime']['percent'], 3).'% observed availability across '.number_format($report['uptime']['checks']->count()).' checks.' }} Target: 99.9%.</p>
        <p class="report-note">Checks run every five minutes. This is sampled availability, not continuous monitoring; periods without probe data are excluded.</p>
        <div class="scroll report-table" style="margin-top:12px"><table><thead><tr><th>Asset</th><th>Type</th><th>Availability</th><th>Checks</th></tr></thead><tbody>
            @forelse ($report['uptime']['assets'] as $asset)<tr><td>{{ $asset['name'] }}</td><td><span class="report-tag">{{ strtoupper($asset['type']) }}</span></td><td>{{ number_format($asset['percent'], 3) }}%</td><td>{{ number_format($asset['checks']) }}</td></tr>@empty<tr><td colspan="4">Per-asset results will appear after scheduled checks have run.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="panel report-section" id="aws-costs">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap">
            <div><h2 style="margin-bottom:4px">AWS cost breakdown</h2><p class="muted">{{ $report['costs']['range_label'] ?? 'Last 6 months' }} · Grouped by AWS service</p></div>
            <button type="button" class="secondary" onclick="window.location.reload()" aria-label="Refresh AWS cost report">Refresh costs</button>
        </div>
        @if ($report['costs']['available'])
            <p style="margin:14px 0 4px"><strong>Total cost: {{ number_format($report['costs']['total'], 2) }} {{ $report['costs']['unit'] }}</strong></p>
            <div style="height:300px;position:relative"><canvas id="aws-cost-chart" role="img" aria-label="Stacked monthly AWS cost chart by service"></canvas></div>
            <div class="scroll report-table" data-report-pagination data-page-size="5" style="margin-top:12px"><table><thead><tr><th>AWS service</th><th>Total for period</th></tr></thead><tbody>
                @foreach ($report['costs']['services'] as $service)<tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:{{ $service['color'] }};margin-right:7px"></span>{{ $service['name'] }}</td><td>{{ number_format(array_sum($service['data']), 2) }} {{ $report['costs']['unit'] }}</td></tr>@endforeach
            </tbody></table></div>
            <div class="report-pagination" data-pagination-controls aria-label="AWS service cost table pagination"><button type="button" data-page-previous aria-label="Previous AWS service cost page">Previous</button><span data-page-status></span><button type="button" data-page-next aria-label="Next AWS service cost page">Next</button></div>
            <script>
                (() => {
                    const canvas = document.getElementById('aws-cost-chart');
                    if (!canvas || !window.Chart) return;
                    const labels = @json($report['costs']['labels']);
                    const services = @json($report['costs']['services']);
                    new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: services.map(service => ({
                                label: service.name,
                                data: service.data,
                                backgroundColor: service.color,
                                borderColor: service.color,
                                borderWidth: 1,
                                borderRadius: 2,
                                maxBarThickness: 54,
                            })),
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'rect', boxWidth: 10, padding: 18, filter: legendItem => legendItem.index < 5 } },
                                tooltip: { callbacks: { label: context => `${context.dataset.label}: ${Number(context.raw).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} {{ $report['costs']['unit'] }}` } },
                            },
                            scales: {
                                x: { stacked: true, grid: { display: false } },
                                y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Cost ({{ $report['costs']['unit'] }})' } },
                            },
                        },
                    });
                })();
            </script>
        @else
            <p style="margin-top:16px">Cost data unavailable. Grant Cost Explorer access to the dashboard's AWS identity, then refresh the report.</p>
        @endif
        <p class="report-note" style="margin-top:12px">{{ $report['costs']['data_note'] ?? 'Fetched live from Cost Explorer; AWS billing data may be delayed.' }}</p>
    </section>

    <section class="panel report-section">
        <h2>Backup policy</h2>
        <div class="scroll report-table"><table><thead><tr><th>Systems</th><th>Reported schedule</th><th>Restore test</th></tr></thead><tbody>@foreach ($report['backups'] as $backup)<tr><td>{{ $backup['asset'] }}</td><td>{{ $backup['policy'] }}</td><td>{{ $backup['restore_test'] }}</td></tr>@endforeach</tbody></table></div>
        <p class="report-note" style="margin-top:10px">Backup schedules are reported policy only and are not verified against AWS or WP Engine. Review backup jobs monthly and test one restore in an isolated environment each quarter.</p>
    </section>

    <section class="panel report-section">
            <h2>Config App change log · {{ $report['ticket_changes']->count() }} for {{ $report['period']['label'] }} · {{ $report['current_ticket_changes']->count() }} this month</h2>
            <h3>{{ $report['period']['label'] }} changes</h3>
            <div class="scroll report-table"><table><thead><tr><th>Date</th><th>Ticket</th><th>Changed by</th><th>What changed</th></tr></thead><tbody>
                @forelse ($report['ticket_changes'] as $change)
                    <tr><td>{{ $change->created_at->format('j M Y, H:i') }}</td><td>{{ $change->ticket?->title ?? 'Deleted ticket' }}</td><td>{{ $change->actor?->name ?? 'Former user' }}</td><td>@forelse ($change->changes as $field => $values)<strong>{{ ucfirst(str_replace('_', ' ', $field)) }}:</strong> {{ \Illuminate\Support\Str::limit((string) ($values['before'] ?? '—'), 70) }} → {{ \Illuminate\Support\Str::limit((string) ($values['after'] ?? '—'), 70) }}<br>@empty Ticket {{ $change->action }} @endforelse</td></tr>
                @empty
                    <tr><td colspan="4">No ticket changes were recorded this month.</td></tr>
                @endforelse
            </tbody></table></div>
            <p class="report-note" style="margin-top:10px">Detailed change history starts from the date this feature was deployed; older edits cannot be reconstructed.</p>
            <h3 style="margin-top:22px">{{ now()->format('F Y') }} so far</h3>
            <div class="scroll report-table"><table><thead><tr><th>Date</th><th>Ticket</th><th>Changed by</th><th>What changed</th></tr></thead><tbody>
                @forelse ($report['current_ticket_changes'] as $change)
                    <tr><td>{{ $change->created_at->format('j M Y, H:i') }}</td><td>{{ $change->ticket?->title ?? 'Deleted ticket' }}</td><td>{{ $change->actor?->name ?? 'Former user' }}</td><td>@forelse ($change->changes as $field => $values)<strong>{{ ucfirst(str_replace('_', ' ', $field)) }}:</strong> {{ \Illuminate\Support\Str::limit((string) ($values['before'] ?? '—'), 70) }} → {{ \Illuminate\Support\Str::limit((string) ($values['after'] ?? '—'), 70) }}<br>@empty Ticket {{ $change->action }} @endforelse</td></tr>
                @empty
                    <tr><td colspan="4">No ticket changes recorded so far this month.</td></tr>
                @endforelse
            </tbody></table></div>
    </section>

    <section class="panel report-section">
            <h2>Systems, access and operating guide</h2>
            <h3>Dashboard access</h3>
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

    <p class="report-footnote">Data is based on reports and checks received by this dashboard. Missing integration data is shown as unavailable, not assumed healthy. The 99.9% target, backup policies and update-fix estimates should be reviewed against provider records.</p>
</div>
<script>
    document.querySelectorAll('[data-open-patch-details]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById(button.dataset.openPatchDetails)?.showModal();
        });
    });
    document.querySelectorAll('[data-close-patch-details]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
    const inspectorMachinesDialog = document.getElementById('inspector-fix-machines-dialog');
    const inspectorMachineSearch = document.getElementById('inspector-machine-search');
    if (inspectorMachinesDialog && inspectorMachineSearch) {
        document.querySelectorAll('[data-open-inspector-machines]').forEach((button) => {
            button.addEventListener('click', () => {
                inspectorMachinesDialog.showModal();
                inspectorMachineSearch.focus();
            });
        });
        document.querySelectorAll('[data-close-inspector-machines]').forEach((button) => {
            button.addEventListener('click', () => inspectorMachinesDialog.close());
        });
        const selectMachine = (row) => {
            const findingsTable = document.querySelector('[data-machine-filter-table]');
            findingsTable?.dispatchEvent(new CustomEvent('inspector-machine-filter', {
                detail: { key: row.dataset.machineKey, name: row.dataset.machineName },
            }));
            inspectorMachinesDialog.close();
        };
        inspectorMachinesDialog.querySelectorAll('[data-inspector-machine-select]').forEach((row) => {
            row.addEventListener('click', () => selectMachine(row));
            row.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectMachine(row);
                }
            });
        });
        inspectorMachineSearch.addEventListener('input', () => {
            const query = inspectorMachineSearch.value.trim().toLocaleLowerCase();
            const rows = Array.from(inspectorMachinesDialog.querySelectorAll('[data-inspector-machine-row]'));
            let visibleCount = 0;

            rows.forEach((row) => {
                row.hidden = query !== '' && !row.textContent.toLocaleLowerCase().includes(query);
                if (!row.hidden) visibleCount++;
            });

            const emptyMessage = inspectorMachinesDialog.querySelector('[data-inspector-machine-empty]');
            if (emptyMessage) emptyMessage.hidden = visibleCount > 0 || rows.length === 0;
        });
        inspectorMachinesDialog.addEventListener('close', () => {
            inspectorMachineSearch.value = '';
            inspectorMachineSearch.dispatchEvent(new Event('input'));
        });
    }
    const inspectorFindingDialog = document.getElementById('inspector-finding-dialog');
    if (inspectorFindingDialog) {
        const inspectorFindings = @json($report['inspector']['findings']);
        const setDetail = (key, value) => {
            const target = inspectorFindingDialog.querySelector(`[data-inspector-detail="${key}"]`);
            if (target) target.textContent = value || 'Not provided';
        };
        const appendCells = (row, values) => {
            values.forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = value || 'Not provided';
                row.appendChild(cell);
            });
        };
        const showInspectorFinding = (index) => {
            const finding = inspectorFindings[index];
            if (!finding) return;
            const resource = finding.resources?.[0] || {};
            const title = inspectorFindingDialog.querySelector('#inspector-finding-title');
            const subtitle = inspectorFindingDialog.querySelector('[data-inspector-finding-subtitle]');
            title.textContent = finding.title || 'Vulnerability finding';
            subtitle.textContent = `Finding ID: ${finding.finding_arn || 'Not provided'}`;
            setDetail('severity', finding.severity);
            setDetail('type', finding.type);
            setDetail('resource', `${resource.name || finding.resource || 'Unknown'} (${resource.id || finding.resource || 'Unknown'})`);
            setDetail('account', resource.account);
            setDetail('cloud_provider', resource.cloud_provider);
            setDetail('region', resource.region || finding.region);
            setDetail('fix_available', finding.fix_available);
            setDetail('exploit_available', finding.exploit_available);
            setDetail('inspector_score', finding.inspector_score === null || finding.inspector_score === undefined ? null : String(finding.inspector_score));
            setDetail('first_observed_at', finding.first_observed_at_display);
            setDetail('age', finding.age_days === null || finding.age_days === undefined ? null : `${finding.age_days} days`);
            setDetail('description', finding.description);
            setDetail('remediation', finding.remediation);
            setDetail('vulnerability_id', finding.vulnerability_id);
            setDetail('vulnerability_source', finding.vulnerability_source);
            setDetail('related_vulnerabilities', (finding.related_vulnerabilities || []).join(', '));
            setDetail('cwes', (finding.cwes || []).join(', '));

            const referenceLinks = inspectorFindingDialog.querySelector('[data-inspector-reference-links]');
            referenceLinks.replaceChildren();
            const urls = [...(finding.vulnerability_source_url ? [finding.vulnerability_source_url] : []), ...(finding.reference_urls || [])];
            [...new Set(urls)].forEach((value) => {
                try {
                    const parsedUrl = new URL(value);
                    if (parsedUrl.protocol !== 'https:') return;
                    const link = document.createElement('a');
                    link.href = parsedUrl.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = parsedUrl.hostname;
                    link.style.marginRight = '10px';
                    referenceLinks.appendChild(link);
                } catch (_) {
                    return;
                }
            });

            const remediationLink = inspectorFindingDialog.querySelector('[data-inspector-remediation-link]');
            let remediationUrl = null;
            try {
                const parsedUrl = new URL(finding.remediation_url || '');
                if (parsedUrl.protocol === 'https:') remediationUrl = parsedUrl.href;
            } catch (_) {
                remediationUrl = null;
            }
            remediationLink.hidden = !remediationUrl;
            remediationLink.href = remediationUrl || '#';

            const cvssRows = inspectorFindingDialog.querySelector('[data-inspector-cvss-rows]');
            cvssRows.replaceChildren();
            (finding.cvss || []).forEach((score) => {
                const row = document.createElement('tr');
                appendCells(row, [score.score === null ? null : String(score.score), score.version, score.source, score.vector]);
                cvssRows.appendChild(row);
            });
            inspectorFindingDialog.querySelector('[data-inspector-cvss-section]').hidden = !(finding.cvss || []).length;

            const packageRows = inspectorFindingDialog.querySelector('[data-inspector-package-rows]');
            packageRows.replaceChildren();
            (finding.package_details || []).forEach((packageInfo) => {
                const row = document.createElement('tr');
                appendCells(row, [packageInfo.name, packageInfo.version, packageInfo.fixed_in_version, packageInfo.package_manager, packageInfo.file_path]);
                packageRows.appendChild(row);
            });
            inspectorFindingDialog.querySelector('[data-inspector-packages-section]').hidden = !(finding.package_details || []).length;
            inspectorFindingDialog.querySelector('[data-inspector-description-section]').hidden = !finding.description;
            inspectorFindingDialog.querySelector('[data-inspector-remediation-section]').hidden = !finding.remediation && !remediationUrl;
            inspectorFindingDialog.showModal();
        };

        document.querySelectorAll('[data-inspector-finding-index]').forEach((row) => {
            row.addEventListener('click', () => showInspectorFinding(Number(row.dataset.inspectorFindingIndex)));
            row.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    showInspectorFinding(Number(row.dataset.inspectorFindingIndex));
                }
            });
        });
        inspectorFindingDialog.querySelector('[data-close-inspector-finding]')?.addEventListener('click', () => inspectorFindingDialog.close());
    }
    document.querySelectorAll('[data-report-pagination]').forEach((tableWrap) => {
        const rows = Array.from(tableWrap.querySelectorAll('tbody tr'));
        const controls = tableWrap.parentElement.querySelector('[data-pagination-controls]');
        const filterSelector = tableWrap.dataset.filterButtons;
        const filterButtons = filterSelector ? Array.from(tableWrap.parentElement.querySelectorAll(filterSelector)) : [];
        const emptyMessage = tableWrap.parentElement.querySelector('[data-inspector-filter-empty]');
        const machineFilterStatus = tableWrap.parentElement.querySelector('[data-inspector-machine-filter-status]');
        const selectedMachineName = tableWrap.parentElement.querySelector('[data-inspector-selected-machine-name]');
        const clearMachineFilter = tableWrap.parentElement.querySelector('[data-clear-inspector-machine]');
        const pageSize = Number(tableWrap.dataset.pageSize) || 5;

        if (!controls || (rows.length <= pageSize && filterButtons.length === 0) || (rows.length === 1 && rows[0].querySelector('td[colspan]'))) {
            controls?.remove();
            return;
        }

        const status = controls.querySelector('[data-page-status]');
        const previous = controls.querySelector('[data-page-previous]');
        const next = controls.querySelector('[data-page-next]');
        let page = 0;
        let selectedSeverity = 'ALL';
        let selectedMachineKey = null;
        let pageCount = 1;

        const renderPage = () => {
            const matchingRows = rows.filter((row) => {
                const severityMatches = selectedSeverity === 'ALL' || row.dataset.severity === selectedSeverity;
                const machineKeys = row.dataset.machineKeys ? row.dataset.machineKeys.split('~') : [];
                const machineMatches = selectedMachineKey === null || machineKeys.includes(selectedMachineKey);

                return severityMatches && machineMatches;
            });
            pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
            page = Math.min(page, pageCount - 1);
            const visibleRows = new Set(matchingRows.slice(page * pageSize, (page + 1) * pageSize));

            rows.forEach((row) => {
                row.hidden = !visibleRows.has(row);
            });
            if (emptyMessage) {
                emptyMessage.hidden = matchingRows.length > 0;
                emptyMessage.textContent = selectedMachineKey !== null && selectedSeverity !== 'ALL'
                    ? 'No findings match this virtual machine and severity.'
                    : (selectedMachineKey !== null ? 'No findings match this virtual machine.' : 'No findings match this severity.');
            }
            status.textContent = filterButtons.length
                ? (matchingRows.length === 0 ? 'No findings for this severity' : `Page ${page + 1} of ${pageCount} · ${matchingRows.length} findings`)
                : `Page ${page + 1} of ${pageCount}`;
            previous.disabled = page === 0;
            next.disabled = page === pageCount - 1;
            controls.hidden = matchingRows.length <= pageSize;
            if (machineFilterStatus) {
                machineFilterStatus.hidden = selectedMachineKey === null;
            }
            filterButtons.forEach((button) => {
                const isSelected = button.dataset.inspectorSeverity === selectedSeverity;
                button.setAttribute('aria-pressed', String(isSelected));
            });
        };

        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                selectedSeverity = button.dataset.inspectorSeverity;
                page = 0;
                renderPage();
            });
        });
        tableWrap.addEventListener('inspector-machine-filter', (event) => {
            selectedMachineKey = event.detail?.key ?? null;
            if (selectedMachineName) selectedMachineName.textContent = event.detail?.name ?? '';
            page = 0;
            renderPage();
        });
        clearMachineFilter?.addEventListener('click', () => {
            selectedMachineKey = null;
            if (selectedMachineName) selectedMachineName.textContent = '';
            page = 0;
            renderPage();
        });

        previous.addEventListener('click', () => {
            if (page > 0) {
                page -= 1;
                renderPage();
            }
        });
        next.addEventListener('click', () => {
            if (page < pageCount - 1) {
                page += 1;
                renderPage();
            }
        });
        renderPage();
    });
</script>
@endsection