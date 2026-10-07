@extends('layouts.app')

@section('content')
    <section class="panel" style="max-width:460px;margin:40px auto;padding:26px;">
        <h1>Recovery codes</h1>
        <p class="muted">Store these codes somewhere secure. Each works once if you lose access to your phone. They will not be shown again.</p>
        <ul style="padding-left:24px;line-height:2;">
            @foreach($codes as $code)
                <li><code style="overflow-wrap:anywhere;">{{ $code }}</code></li>
            @endforeach
        </ul>
        <a href="{{ route('dashboard') }}">Continue to dashboard</a>
    </section>
@endsection