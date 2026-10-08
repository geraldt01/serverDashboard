@if($errors->any())
    <div class="ticket-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="form-grid ticket-form">
    <div class="ticket-full"><label for="ticket-title">Issue title</label><input id="ticket-title" name="title" maxlength="255" value="{{ old('title', $ticket->title) }}" required></div>
    <div class="ticket-full"><label for="ticket-issue">Issue details</label><textarea id="ticket-issue" name="issue" maxlength="20000" required>{{ old('issue', $ticket->issue) }}</textarea></div>
    <div><label for="ticket-reporter">Reported by</label><input id="ticket-reporter" name="reported_by" maxlength="255" value="{{ old('reported_by', $ticket->reported_by) }}" required></div>
    <div><label for="ticket-status">Status</label><select id="ticket-status" name="status" required>@foreach(\App\Models\SupportTicket::STATUSES as $value => $label)<option value="{{ $value }}" @selected(old('status', $ticket->status) === $value)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="ticket-start">Start time ({{ config('app.timezone') }})</label><input id="ticket-start" name="started_at" type="datetime-local" value="{{ old('started_at', $ticket->started_at?->format('Y-m-d\TH:i')) }}"></div>
    <div><label for="ticket-finish">Finished time ({{ config('app.timezone') }})</label><input id="ticket-finish" name="finished_at" type="datetime-local" value="{{ old('finished_at', $ticket->finished_at?->format('Y-m-d\TH:i')) }}"></div>
    <div class="ticket-full"><label for="ticket-files">{{ $ticket->exists ? 'Add attachments' : 'Attachments' }} (up to 5 files, 10 MB each)</label><input id="ticket-files" name="attachments[]" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip"></div>
    <div class="ticket-full actions"><button type="submit">{{ $ticket->exists ? 'Save changes' : 'Create ticket' }}</button><a class="ticket-command" href="{{ route('support-tickets.index') }}">Back to tickets</a></div>
</div>
<script>
    (() => {
        const status = document.getElementById('ticket-status');
        const start = document.getElementById('ticket-start');
        const finish = document.getElementById('ticket-finish');
        const updateTimes = () => {
            start.required = status.value !== 'open';
            finish.required = status.value === 'resolved';
            finish.disabled = status.value !== 'resolved';
            finish.min = start.value;
        };
        status.addEventListener('change', () => {
            if (status.value !== 'resolved') finish.value = '';
            updateTimes();
        });
        start.addEventListener('change', updateTimes);
        updateTimes();
    })();
</script>