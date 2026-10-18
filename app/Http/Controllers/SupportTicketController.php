<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketChange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_keys(SupportTicket::STATUSES))],
        ]);
        $tickets = SupportTicket::query()->withCount('attachments')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhere('reported_by', 'like', '%'.$search.'%')
                        ->orWhere('issue', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return view('support-tickets.index', compact('tickets'));
    }

    public function create()
    {
        return view('support-tickets.create', ['ticket' => new SupportTicket(['status' => 'open'])]);
    }

    public function store(Request $request)
    {
        $ticket = $this->saveTicket($request, new SupportTicket);

        return redirect()->route('support-tickets.show', $ticket)->with('status', 'Support ticket created.');
    }

    public function show(SupportTicket $supportTicket)
    {
        return view('support-tickets.show', ['ticket' => $supportTicket->load('attachments', 'creator', 'changes.actor')]);
    }

    public function update(Request $request, SupportTicket $supportTicket)
    {
        $this->saveTicket($request, $supportTicket);

        return redirect()->route('support-tickets.show', $supportTicket)->with('status', 'Support ticket updated.');
    }

    public function destroy(SupportTicket $supportTicket)
    {
        try {
            $attachmentPaths = $supportTicket->attachments()->pluck('path')->all();
            if ($attachmentPaths !== [] && ! Storage::disk('s3')->delete($attachmentPaths)) {
                throw new RuntimeException('Support ticket attachment deletion failed.');
            }
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('support-tickets.show', $supportTicket)
                ->with('error', 'The ticket could not be deleted because its attachments could not be removed. Please try again.');
        }

        $supportTicket->delete();

        return redirect()->route('support-tickets.index')->with('status', 'Support ticket deleted.');
    }

    public function download(SupportTicket $supportTicket, SupportTicketAttachment $attachment)
    {
        abort_unless($attachment->support_ticket_id === $supportTicket->id, 404);
        $disk = Storage::disk('s3');
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function saveTicket(Request $request, SupportTicket $ticket): SupportTicket
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'issue' => ['required', 'string', 'max:20000'],
            'reported_by' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(SupportTicket::STATUSES))],
            'started_at' => ['nullable', 'required_if:status,in_progress,resolved', 'date_format:Y-m-d\TH:i'],
            'finished_at' => ['nullable', 'required_if:status,resolved', 'prohibited_unless:status,resolved', 'date_format:Y-m-d\TH:i', 'after_or_equal:started_at'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xls,xlsx,zip', 'extensions:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xls,xlsx,zip'],
        ]);
        unset($data['attachments']);
        $data['started_at'] = $data['started_at'] ?? null;
        $data['finished_at'] = $data['finished_at'] ?? null;
        if (! $ticket->exists) {
            $data['created_by'] = $request->user()->id;
        }
        $paths = [];
        $isNew = ! $ticket->exists;
        $fields = ['title', 'issue', 'reported_by', 'status', 'started_at', 'finished_at'];
        $before = [];
        foreach ($fields as $field) {
            $before[$field] = $this->normalizeChangeValue($field, $ticket->getAttribute($field));
        }

        try {
            DB::transaction(function () use ($ticket, $data, $request, &$paths, $fields, $before, $isNew) {
                $ticket->fill($data)->save();
                $changes = [];
                foreach ($fields as $field) {
                    $after = $this->normalizeChangeValue($field, $data[$field] ?? null);
                    if ($isNew || $before[$field] !== $after) {
                        $changes[$field] = ['before' => $isNew ? null : $before[$field], 'after' => $after];
                    }
                }
                $addedAttachments = [];
                foreach ($request->file('attachments', []) as $file) {
                    $directory = 'support-tickets/'.$ticket->id;
                    $filename = Str::uuid().'.'.$file->extension();
                    $path = $directory.'/'.$filename;
                    $paths[] = $path;
                    $stored = Storage::disk('s3')->putFileAs($directory, $file, $filename, [
                        'visibility' => 'private',
                        'ACL' => 'bucket-owner-full-control',
                        'ContentType' => 'application/octet-stream',
                    ]);
                    if ($stored === false) {
                        throw new RuntimeException('Support ticket attachment upload failed.');
                    }
                    $originalName = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 255, '');
                    $ticket->attachments()->create([
                        'original_name' => $originalName,
                        'path' => $path,
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => $file->getSize(),
                    ]);
                    $addedAttachments[] = $originalName;
                }
                if ($addedAttachments !== []) {
                    $changes['attachments'] = ['before' => null, 'after' => implode(', ', $addedAttachments)];
                }
                SupportTicketChange::create([
                    'support_ticket_id' => $ticket->id,
                    'actor_id' => $request->user()->id,
                    'action' => $isNew ? 'created' : 'updated',
                    'changes' => $changes,
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);
            if ($paths) {
                try {
                    if (! Storage::disk('s3')->delete($paths)) {
                        report(new RuntimeException('Could not clean up failed support ticket uploads: '.implode(', ', $paths)));
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw ValidationException::withMessages([
                'attachments' => 'The ticket could not be saved. Please try again. If files were selected, check the S3 configuration and select them again.',
            ]);
        }

        return $ticket;
    }

    private function normalizeChangeValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['started_at', 'finished_at'], true)) {
            return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
