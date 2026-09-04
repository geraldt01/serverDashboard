@extends('layouts.app')

@section('content')
    @if(auth()->user()->isAdmin())
        @include('partials.admin-sidebar')
    @endif
    <header class="panel topbar">
        <div><h1>Monitoring Dashboard</h1><p class="muted">{{ auth()->user()->email }} · {{ auth()->user()->role }}</p></div>
        <div class="actions">
            @if(auth()->user()->isAdmin())
                <form method="POST" action="{{ route('monitor.ec2.sync') }}">@csrf<button type="submit">Sync EC2 updates</button></form>
            @endif
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form>
        </div>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if(session('wordpressSiteCredentials'))<section class="panel content" style="margin:16px 0;border-color:#b66a00;"><h2>Copy WordPress Reporter Credentials Now</h2><p class="muted">These credentials are shown once. Save them in the WordPress reporter settings, then they will no longer be displayed here.</p><p><strong>Site:</strong> {{ session('wordpressSiteCredentials.siteName') }}</p><p><strong>Endpoint:</strong> <code>{{ session('wordpressSiteCredentials.endpoint') }}</code></p><p><strong>Token:</strong> <code>{{ session('wordpressSiteCredentials.token') }}</code></p></section>@endif
    @if(in_array(parse_url(config('dashboard.public_url'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true))<section class="panel content" style="margin:16px 0;border-color:#b66a00;"><h2>Local Dashboard Mode</h2><p class="muted">A public WordPress website cannot send reports to <code>{{ config('dashboard.public_url') }}</code>. Deploy this dashboard to a public HTTPS domain, update <code>DASHBOARD_PUBLIC_URL</code>, rebuild Laravel caches, then rotate the site token and configure the new HTTPS endpoint in WordPress.</p></section>@endif

    <section class="meters">
        <article class="panel meter"><h3>Traffic (last 24h)</h3><div class="value">{{ number_format($trafficLast24h) }}</div></article>
        <article class="panel meter"><h3>Outdated WordPress plugins</h3><div class="value">{{ number_format($outdatedPlugins) }}</div></article>
        <article class="panel meter"><h3>Outdated WordPress core</h3><div class="value">{{ number_format($outdatedCoreSites) }}</div></article>
        <article class="panel meter"><h3>EC2 missing patches</h3><div class="value">{{ number_format($ec2MissingPatches) }}</div></article>
    </section>

    <section class="grid">
        <article class="panel wide content"><h2>Website Traffic Trend</h2><div class="chart-area"><canvas id="trafficChart"></canvas></div></article>
        <article class="panel content"><h2>WordPress Plugin Updates</h2><div class="scroll"><table id="pluginsTable" data-paginate="5"><thead><tr><th>Site</th><th>Plugin</th><th>Current</th><th>Latest</th><th>Status</th></tr></thead><tbody>@forelse($plugins as $plugin)<tr><td>{{ $plugin->site_name }}</td><td>{{ $plugin->plugin_name }}</td><td>{{ $plugin->current_version }}</td><td>{{ $plugin->latest_version }}</td><td><span class="badge {{ $plugin->status === 'outdated' ? 'warning' : 'ok' }}">{{ $plugin->status }}</span></td></tr>@empty<tr><td colspan="5">No plugin reports received.</td></tr>@endforelse</tbody></table></div><div class="pagination" data-pagination-for="pluginsTable"></div></article>
        <article class="panel content"><h2>EC2 Patch Status</h2><div class="scroll"><table id="ec2Table" data-paginate="5"><thead><tr><th>Instance</th><th>OS Version</th><th>Missing</th><th>Security</th><th>Installed</th><th>Failed</th><th>Reboot</th></tr></thead><tbody>@forelse($instances as $instance)<tr><td>{{ $instance->instance_name }}<br><span class="muted">{{ $instance->instance_id }}</span></td><td>{{ $instance->os_version ?? '—' }}</td><td>{{ $instance->missing_count }}</td><td><span class="badge {{ $instance->security_count > 0 ? 'danger' : 'ok' }}">{{ $instance->security_count }}</span></td><td>{{ $instance->installed_count }}</td><td>{{ $instance->failed_count }}</td><td><span class="badge {{ $instance->reboot_required ? 'warning' : 'ok' }}">{{ $instance->reboot_required ? 'required' : 'no' }}</span></td></tr>@empty<tr><td colspan="7">No EC2 reports received.</td></tr>@endforelse</tbody></table></div><div class="pagination" data-pagination-for="ec2Table"></div></article>
        <article class="panel content"><h2>WordPress Core Updates</h2><div class="scroll"><table id="coreUpdatesTable" data-paginate="5"><thead><tr><th>Site</th><th>Current</th><th>Latest</th><th>Status</th></tr></thead><tbody>@forelse($coreUpdates as $coreUpdate)<tr><td>{{ $coreUpdate->site_name }}</td><td>{{ $coreUpdate->current_version }}</td><td>{{ $coreUpdate->latest_version }}</td><td><span class="badge {{ $coreUpdate->status === 'outdated' ? 'warning' : 'ok' }}">{{ $coreUpdate->status }}</span></td></tr>@empty<tr><td colspan="4">No core update reports received.</td></tr>@endforelse</tbody></table></div><div class="pagination" data-pagination-for="coreUpdatesTable"></div></article>
        <article class="panel content"><h2>Recent WP-Admin Logins</h2><div class="scroll"><table id="loginsTable" data-paginate="5"><thead><tr><th>Site</th><th>User</th><th>IP address</th><th>Status</th><th>When</th></tr></thead><tbody>@forelse($recentLogins as $login)<tr><td>{{ $login->site_name }}</td><td>{{ $login->username }}</td><td>{{ $login->ip_address }}</td><td>@if($login->is_authorized === false)<span class="badge danger">Unauthorized</span>@elseif($login->is_authorized === true)<span class="badge ok">Whitelisted</span>@else<span class="badge">Not checked</span>@endif</td><td>{{ $login->logged_in_at->diffForHumans() }}</td></tr>@empty<tr><td colspan="5">No login events received.</td></tr>@endforelse</tbody></table></div><div class="pagination" data-pagination-for="loginsTable"></div></article>
    </section>
    <script>
        const rows = @json($trafficRows);
        const labels = [...new Set(rows.map(row => row.day))];
        const sites = [...new Set(rows.map(row => row.site_name))];
        const colors = ['#0f766e', '#a15c00', '#2563b8', '#bb2857'];
        new Chart(document.getElementById('trafficChart'), { type: 'line', data: { labels, datasets: sites.map((site, index) => ({ label: site, data: labels.map(day => Number(rows.find(row => row.day === day && row.site_name === site)?.visits || 0)), borderColor: colors[index % colors.length], backgroundColor: colors[index % colors.length], tension: .25 })) }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } });

        function paginateTable(table) {
            const pageSize = Number(table.dataset.paginate);
            const rows = Array.from(table.tBodies[0].rows);
            const pager = document.querySelector(`.pagination[data-pagination-for="${table.id}"]`);
            if (!pager || rows.length <= pageSize) return;

            const totalPages = Math.ceil(rows.length / pageSize);
            let page = 1;

            function render() {
                rows.forEach((row, index) => {
                    row.style.display = (index >= (page - 1) * pageSize && index < page * pageSize) ? '' : 'none';
                });
                pager.innerHTML = '';
                const prev = Object.assign(document.createElement('button'), { type: 'button', className: 'secondary', textContent: 'Previous', disabled: page === 1 });
                prev.addEventListener('click', () => { page--; render(); });
                const info = Object.assign(document.createElement('span'), { className: 'muted', textContent: `Page ${page} of ${totalPages}` });
                const next = Object.assign(document.createElement('button'), { type: 'button', className: 'secondary', textContent: 'Next', disabled: page === totalPages });
                next.addEventListener('click', () => { page++; render(); });
                pager.append(prev, info, next);
            }

            render();
        }

        document.querySelectorAll('table[data-paginate]').forEach(paginateTable);
    </script>
@endsection