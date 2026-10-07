@extends('layouts.app')

@section('content')
    <section class="panel" style="max-width:460px;margin:40px auto;padding:26px;">
        <h1>{{ $secret ? 'Set up authenticator' : 'Verify your login' }}</h1>
        @if($secret)
            <p class="muted">Scan with Google Authenticator, Microsoft Authenticator, or another TOTP app.</p>
            <img src="{{ $qrCode }}" alt="Authenticator enrollment QR code" width="240" height="240" style="display:block;max-width:100%;height:auto;margin:18px auto;">
            <details style="margin-bottom:18px;">
                <summary>Manual setup key</summary>
                <code style="display:block;overflow-wrap:anywhere;margin-top:10px;">{{ $secret }}</code>
            </details>
        @endif
        <form method="POST" action="{{ route('mfa.verify') }}">
            @csrf
            <label for="code">{{ $secret ? 'Authenticator code' : 'Authenticator or recovery code' }}</label>
            <input id="code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required autofocus spellcheck="false" autocapitalize="none" @if($secret) inputmode="numeric" pattern="[0-9]{6}" @endif>
            @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
            <button type="submit" style="width:100%;margin-top:18px;">{{ $secret ? 'Enable MFA' : 'Verify' }}</button>
        </form>
        <form method="POST" action="{{ route('mfa.cancel') }}" style="margin-top:12px;">
            @csrf
            <button type="submit" class="secondary" style="width:100%;">Back to sign in</button>
        </form>
    </section>
@endsection