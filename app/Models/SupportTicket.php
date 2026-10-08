<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved'];

    protected $fillable = ['title', 'issue', 'reported_by', 'status', 'started_at', 'finished_at', 'created_by'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
