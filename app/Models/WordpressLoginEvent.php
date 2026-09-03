<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WordpressLoginEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'wordpress_site_id',
        'site_name',
        'username',
        'ip_address',
        'user_agent',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function wordpressSite()
    {
        return $this->belongsTo(WordpressSite::class);
    }
}
