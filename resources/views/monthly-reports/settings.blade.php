@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    <section class="panel">
        <div class="topbar">
            <div><h1>Reporting Settings</h1><p class="muted">Monthly IT and security report delivery</p></div>
        </div>
        <div class="content">
            @if (session('status'))<div class="notice">{{ session('status') }}</div>@endif
            <form method="POST" action="{{ route('monthly-reports.settings.update') }}" class="form-grid">
                @csrf
                <div>
                    <label for="manager_email">Manager email</label>
                    <input id="manager_email" name="manager_email" type="email" required maxlength="255" value="{{ old('manager_email', $settings->manager_email) }}">
                    @error('manager_email')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="actions" style="align-items:end"><button type="submit">Save recipient</button></div>
            </form>
            <h2 style="margin-top:24px">Public report link</h2>
            <p><a href="{{ route('monthly-reports.public', ['token' => $settings->public_token]) }}">{{ route('monthly-reports.public', ['token' => $settings->public_token]) }}</a></p>
            <p class="muted">Anyone with this link can view the report. Keep it private; rotating it immediately invalidates the old link.</p>
            <form method="POST" action="{{ route('monthly-reports.settings.rotate') }}" onsubmit="return confirm('Rotate the public link? The existing emailed link will stop working.');">
                @csrf
                <button class="secondary" type="submit">Rotate public link</button>
            </form>
            <p class="muted" style="margin-top:18px">Scheduled delivery: 5th of each month. Last sent: {{ $settings->last_sent_at?->format('Y-m-d H:i T') ?? 'Not sent yet' }}.</p>
        </div>
    </section>
@endsection