<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtherServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'hostname',
        'monitor_token_encrypted',
        'is_active',
        'os_name',
        'total_updates',
        'security_updates',
        'reboot_required',
        'last_reported_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reboot_required' => 'boolean',
        'last_reported_at' => 'datetime',
        'monitor_token_encrypted' => 'encrypted',
    ];

    public function setMonitoringToken(string $token): void
    {
        $this->monitor_token = hash('sha256', $token);
        $this->monitor_token_encrypted = $token;
    }

    public function monitoringToken(): string
    {
        return (string) $this->monitor_token_encrypted;
    }
}
