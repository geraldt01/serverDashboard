<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WordpressSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'url',
        'monitor_token_encrypted',
        'is_active',
        'last_plugin_count',
        'last_outdated_count',
        'last_reported_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function pluginUpdates()
    {
        return $this->hasMany(WordpressPluginUpdate::class);
    }
}
