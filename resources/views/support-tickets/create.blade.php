@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    @include('support-tickets.styles')
    <header class="panel topbar"><h1>New Support Ticket</h1><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form></header>
    <section class="panel content ticket-section">
        <form method="POST" action="{{ route('support-tickets.store') }}" enctype="multipart/form-data">
            @csrf
            @include('support-tickets.form')
        </form>
    </section>
@endsection