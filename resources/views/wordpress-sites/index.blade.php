@extends('layouts.app')

@section('content')
    <style>
        dialog.wp-whitelist-dialog { border:none; border-radius:8px; padding:0; width:min(440px,90vw); }
        dialog.wp-whitelist-dialog::backdrop { background:rgba(15,23,42,.45); }
        dialog.wp-whitelist-dialog .modal-body { padding:20px; }
        dialog.wp-whitelist-dialog textarea { width:100%; }
        dialog.wp-whitelist-dialog .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:14px; }
        .whitelist-cell { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    </style>
    @include('partials.admin-sidebar')
    <header class="panel topbar">
        <div><h1>WordPress Sites</h1><p class="muted">Manage site reporting and reporter credentials.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if(session('wordpressSiteCredentials'))<section class="panel content" style="margin:16px 0;border-color:#b66a00;"><h2>Copy WordPress Reporter Credentials Now</h2><p class="muted">These credentials are shown once. Save them in the WordPress reporter settings, then they will no longer be displayed here.</p><p><strong>Site:</strong> {{ session('wordpressSiteCredentials.siteName') }}</p><p><strong>Endpoint:</strong> <code>{{ session('wordpressSiteCredentials.endpoint') }}</code></p><p><strong>Token:</strong> <code>{{ session('wordpressSiteCredentials.token') }}</code></p></section>@endif

    <section class="panel content" style="margin-top:16px;"><h2>Add WordPress Site</h2><form method="POST" action="{{ route('wordpress-sites.store') }}" class="form-grid">@csrf<div><label for="wordpress-site-name">Site name</label><input id="wordpress-site-name" name="name" value="{{ old('name') }}" required><span class="error">@error('name'){{ $message }}@enderror</span></div><div><label for="wordpress-site-url">WordPress URL</label><input id="wordpress-site-url" name="url" type="url" placeholder="https://example.com" value="{{ old('url') }}" required><span class="error">@error('url'){{ $message }}@enderror</span></div><div style="align-self:end;"><button type="submit">Add WordPress site</button></div></form><p class="muted" style="margin:14px 0;">Install the <a href="{{ asset('downloads/serverdashboard-reporter.zip') }}">WordPress reporter plugin ZIP</a>. Production reporter endpoints require HTTPS; local HTTP works only when WordPress and the dashboard run on the same machine with WP_DEBUG enabled.</p></section>
    <section class="panel content" style="margin-top:14px;"><h2>Registered Sites</h2><div class="scroll"><table><thead><tr><th>Site</th><th>Status</th><th>Last report</th><th>Plugins</th><th>Updates</th><th>IP whitelist</th><th>Security actions</th></tr></thead><tbody>@forelse($wordpressSites as $site)<tr><td><strong>{{ $site->name }}</strong><br><a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer">{{ $site->url }}</a></td><td><span class="badge {{ $site->is_active ? 'ok' : 'danger' }}">{{ $site->is_active ? 'enabled' : 'disabled' }}</span></td><td>{{ $site->last_reported_at?->diffForHumans() ?? 'No report yet' }}</td><td>{{ $site->last_plugin_count }}</td><td><span class="badge {{ $site->last_outdated_count > 0 ? 'warning' : 'ok' }}">{{ $site->last_outdated_count }}</span></td><td><div class="whitelist-cell"><span class="badge {{ $site->ip_whitelist ? 'ok' : '' }}">{{ $site->ip_whitelist ? count($site->whitelistedIps()) . ' IP(s)' : 'Not set' }}</span><button type="button" class="secondary" data-open-whitelist="whitelist-dialog-{{ $site->id }}">Edit whitelist</button></div><dialog id="whitelist-dialog-{{ $site->id }}" class="wp-whitelist-dialog"><form method="POST" action="{{ route('wordpress-sites.update-whitelist', $site) }}" class="modal-body">@csrf<h2>IP whitelist &ndash; {{ $site->name }}</h2><p class="muted">One IP or CIDR per line, e.g. 203.0.113.7 or 198.51.100.0/24. Leave empty to allow logins from any IP.</p><textarea name="ip_whitelist" rows="6" placeholder="One IP or CIDR per line, e.g. 203.0.113.7">{{ $site->ip_whitelist }}</textarea><div class="modal-actions"><button type="button" class="secondary" data-close-whitelist>Cancel</button><button type="submit">Save whitelist</button></div></form></dialog></td><td><div class="actions"><form method="POST" action="{{ route('wordpress-sites.rotate-token', $site) }}">@csrf<button type="submit">Rotate token</button></form><form method="POST" action="{{ route('wordpress-sites.toggle-active', $site) }}">@csrf<button type="submit" class="secondary">{{ $site->is_active ? 'Disable reporting' : 'Enable reporting' }}</button></form></div></td></tr>@empty<tr><td colspan="7">No WordPress sites registered.</td></tr>@endforelse</tbody></table></div></section>
    <script>
        document.querySelectorAll('[data-open-whitelist]').forEach((btn) => {
            btn.addEventListener('click', () => document.getElementById(btn.dataset.openWhitelist)?.showModal());
        });
        document.querySelectorAll('[data-close-whitelist]').forEach((btn) => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
    </script>
@endsection