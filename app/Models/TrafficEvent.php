<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrafficEvent extends Model
{
    use HasFactory;

    protected $fillable = ['site_name', 'visits', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
