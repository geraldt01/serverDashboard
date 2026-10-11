<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UptimeCheck extends Model
{
    protected $fillable = ['asset_type', 'asset_key', 'asset_name', 'is_up', 'http_status', 'response_time_ms', 'detail', 'checked_at'];

    protected $casts = ['is_up' => 'boolean', 'checked_at' => 'datetime'];
}