<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtherServerPatchReport extends Model
{
    protected $fillable = [
        'other_server_id',
        'total_updates',
        'security_updates',
        'reboot_required',
        'update_details',
        'security_update_details',
        'reported_at',
    ];

    protected $casts = [
        'reboot_required' => 'boolean',
        'reported_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(OtherServer::class, 'other_server_id');
    }
}