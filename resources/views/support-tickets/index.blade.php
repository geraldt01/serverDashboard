@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    @include('support-tickets.styles')
    <header class="panel topbar">
        <h1>Support Ticketing</h1>
        <div class="actions"><a class="ticket-command" href="{{ route('support-tickets.create') }}">New ticket</a><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form></div>
    </header>
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    <section class="panel content ticket-section">
        <form method="GET" action="{{ route('support-tickets.index') }}" class="form-grid ticket-form">
            <div><label for="ticket-search">Search tickets</label><input id="ticket-search" type="search" name="search" maxlength="255" value="{{ request('search') }}"></div>
            <div><label for="ticket-filter-status">Status</label><select id="ticket-filter-status" name="status"><option value="">All statuses</option>@foreach(\App\Models\SupportTicket::STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="ticket-full actions"><button type="submit">Search</button><a class="ticket-command" href="{{ route('support-tickets.index') }}">Clear filters</a></div>
        </form>
        <div class="scroll ticket-section">
            <table class="ticket-table">
                <thead><tr><th>Ticket</th><th>Reported by</th><th>Status</th><th>Start time</th><th>Finished time</th><th>Attachments</th></tr></thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        <tr>
                            <td class="ticket-subject"><a href="{{ route('support-tickets.show', $ticket) }}"><strong>#{{ $ticket->id }} {{ $ticket->title }}</strong></a><br><span class="muted">{{ $ticket->created_at->format('M d, Y H:i') }}</span></td>
                            <td class="ticket-subject">{{ $ticket->reported_by }}</td>
                            <td><span class="badge ticket-status {{ $ticket->status === 'resolved' ? 'ok' : ($ticket->status === 'in_progress' ? 'warning' : '') }}">{{ \App\Models\SupportTicket::STATUSES[$ticket->status] }}</span></td>
                            <td>{{ $ticket->started_at?->format('M d, Y H:i') ?? 'Not started' }}</td>
                            <td>{{ $ticket->finished_at?->format('M d, Y H:i') ?? 'Not finished' }}</td>
                            <td>{{ $ticket->attachments_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No support tickets found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <nav class="ticket-pagination" aria-label="Ticket pagination">
            @if($tickets->previousPageUrl())<a href="{{ $tickets->previousPageUrl() }}" rel="prev">Previous</a>@endif
            <span class="muted">Page {{ $tickets->currentPage() }} of {{ $tickets->lastPage() }} &middot; {{ $tickets->total() }} tickets &middot; {{ config('app.timezone') }}</span>
            @if($tickets->nextPageUrl())<a href="{{ $tickets->nextPageUrl() }}" rel="next">Next</a>@endif
        </nav>
    </section>
@endsection