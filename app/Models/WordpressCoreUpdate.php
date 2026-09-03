<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WordpressCoreUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'wordpress_site_id',
        'site_name',
        'current_version',
        'latest_version',
        'status',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function wordpressSite()
    {
        return $this->belongsTo(WordpressSite::class);
    }
}
