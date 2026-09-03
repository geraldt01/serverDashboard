@extends('layouts.app')

@section('content')
    <section class="panel" style="max-width:420px;margin:80px auto;padding:26px;">
        <h1>ServerDashboard</h1>
        <p class="muted" style="margin-bottom:22px;">WordPress, website traffic, and EC2 patch monitoring.</p>
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <div style="margin-bottom:14px;"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus></div>
            <div style="margin-bottom:14px;"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
            @error('email')<p class="error">{{ $message }}</p>@enderror
            <button type="submit" style="width:100%;">Sign in</button>
        </form>
    </section>
@endsection