@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    <header class="panel topbar">
        <div><h1>Frontend Page Checks</h1><p class="muted">Monitor webpages for broken images, broken videos, and missing page elements.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif

    <section class="panel content" style="margin-top:16px;">
        <h2>Add Webpage</h2>
        <form method="POST" action="{{ route('webpage-checks.store') }}" class="form-grid">
            @csrf
            <div><label for="webpage-check-name">Name</label><input id="webpage-check-name" name="name" value="{{ old('name') }}" required><span class="error">@error('name'){{ $message }}@enderror</span></div>
            <div><label for="webpage-check-url">URL</label><input id="webpage-check-url" name="url" type="url" placeholder="https://example.com" value="{{ old('url') }}" required><span class="error">@error('url'){{ $message }}@enderror</span></div>
            <div><label for="webpage-check-elements">Required elements (optional)</label><input id="webpage-check-elements" name="requiredElements" placeholder="#header, #footer, .site-nav" value="{{ old('requiredElements') }}"><span class="error">@error('requiredElements'){{ $message }}@enderror</span></div>
            <div style="align-self:end;"><button type="submit">Add &amp; run first check</button></div>
        </form>
        <p class="muted" style="margin-top:8px;">Only public http/https addresses are allowed &mdash; internal, loopback, and link-local hosts (including the cloud metadata service) are always rejected.</p>
    </section>

    <section class="panel content" style="margin-top:14px;">
        <h2>Registered Webpages</h2>
        <div class="scroll">
            <table>
                <thead><tr><th>Page</th><th>Status</th><th>HTTP</th><th>Response</th><th>Images</th><th>Videos</th><th>Elements</th><th>Last checked</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($webpageChecks as $check)
                    <tr>
                        <td><strong>{{ $check->name }}</strong><br><span class="muted">{{ $check->url }}</span></td>
                        <td><span class="badge {{ $check->last_status === 'healthy' ? 'ok' : ($check->last_status === 'warning' ? 'warning' : ($check->last_status === 'broken' ? 'danger' : '')) }}">{{ $check->last_status }}</span> <span class="badge {{ $check->is_active ? 'ok' : '' }}">{{ $check->is_active ? 'enabled' : 'disabled' }}</span></td>
                        <td>{{ $check->last_http_status ?? '—' }}</td>
                        <td>{{ $check->last_response_time_ms !== null ? $check->last_response_time_ms . ' ms' : '—' }}</td>
                        <td><span class="badge {{ $check->broken_images_count > 0 ? 'danger' : 'ok' }}">{{ $check->broken_images_count }}</span></td>
                        <td><span class="badge {{ $check->broken_videos_count > 0 ? 'danger' : 'ok' }}">{{ $check->broken_videos_count }}</span></td>
                        <td><span class="badge {{ $check->missing_elements_count > 0 ? 'warning' : 'ok' }}">{{ $check->missing_elements_count }}</span></td>
                        <td>{{ optional($check->last_checked_at)->diffForHumans() ?? 'never' }}</td>
                        <td>
                            <div class="actions">
                                <form method="POST" action="{{ route('webpage-checks.run', $check) }}">@csrf<button type="submit">Run check now</button></form>
                                <form method="POST" action="{{ route('webpage-checks.toggle-active', $check) }}">@csrf<button type="submit" class="{{ $check->is_active ? 'btn-danger' : 'secondary' }}">{{ $check->is_active ? 'Disable' : 'Enable' }}</button></form>
                                <form method="POST" action="{{ route('webpage-checks.destroy', $check) }}" onsubmit="return confirm('Remove {{ $check->name }} from frontend checks?');">@csrf @method('DELETE')<button type="submit" class="btn-danger">Remove</button></form>
                            </div>
                            @if($check->issues)
                                <details style="margin-top:8px;">
                                    <summary class="muted" style="cursor:pointer;">View issues ({{ count($check->issues) }})</summary>
                                    <ul style="margin-top:6px;">
                                        @foreach($check->issues as $issue)
                                            <li>{{ $issue }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            <details style="margin-top:8px;">
                                <summary class="muted" style="cursor:pointer;">Edit required elements</summary>
                                <form method="POST" action="{{ route('webpage-checks.update-required-elements', $check) }}" class="form-grid" style="margin-top:8px;">
                                    @csrf
                                    <div><label for="webpage-check-elements-{{ $check->id }}">Required elements</label><input id="webpage-check-elements-{{ $check->id }}" name="requiredElements" placeholder="#header, #footer, .site-nav" value="{{ old('requiredElements', $check->required_elements) }}"></div>
                                    <div style="align-self:end;"><button type="submit" class="secondary">Save</button></div>
                                </form>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">No webpages registered yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:10px;">Each check fetches the page's HTML and verifies that it loads successfully, that its <code>&lt;img&gt;</code> and <code>&lt;video&gt;</code> sources respond, and that any required elements you list (by <code>#id</code>, <code>.class</code>, or tag name) exist in the markup. This is a basic HTML-level check &mdash; it does not run JavaScript or render the page visually, so purely visual/layout problems won't be caught.</p>
        <p class="muted">Enabled pages are re-checked automatically on an hourly schedule, in addition to any manual "Run check now".</p>
    </section>
@endsection
