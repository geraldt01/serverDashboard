@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    @include('support-tickets.styles')
    <header class="panel topbar"><div><h1>Support Ticket #{{ $ticket->id }}</h1><div class="ticket-meta muted"><span>Created {{ $ticket->created_at->format('M d, Y H:i') }}</span><span>Logged by {{ $ticket->creator?->name ?? 'Former user' }}</span></div></div><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form></header>
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    <section class="panel content ticket-section">
        <h2>Ticket details</h2>
        <form method="POST" action="{{ route('support-tickets.update', $ticket) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            @include('support-tickets.form')
        </form>
    </section>
    <section class="panel content ticket-section">
        <h2>Attachments ({{ $ticket->attachments->count() }})</h2>
        <ul class="ticket-attachments">
            @forelse($ticket->attachments as $attachment)
                <li><div class="ticket-filename"><strong>{{ $attachment->original_name }}</strong><br><span class="muted">{{ number_format($attachment->size / 1024, 1) }} KB &middot; {{ $attachment->created_at->format('M d, Y H:i') }}</span></div><a class="ticket-command" href="{{ route('support-tickets.attachments.download', [$ticket, $attachment]) }}">Download</a></li>
            @empty
                <li class="muted">No attachments.</li>
            @endforelse
        </ul>
    </section>
@endsection