<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ec2PatchStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'instance_id',
        'instance_name',
        'missing_count',
        'security_count',
        'installed_count',
        'failed_count',
        'reboot_required',
        'os_version',
        'checked_at',
    ];

    protected $casts = [
        'reboot_required' => 'boolean',
        'checked_at' => 'datetime',
    ];
}
