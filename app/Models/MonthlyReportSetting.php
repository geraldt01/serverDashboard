<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyReportSetting extends Model
{
    protected $fillable = ['manager_email', 'public_token', 'public_token_hash', 'last_sent_at'];

    protected $casts = [
        'public_token' => 'encrypted',
        'last_sent_at' => 'datetime',
    ];

    public static function current(): self
    {
        $setting = static::query()->firstOrCreate(['id' => 1]);

        if (! $setting->public_token || ! $setting->public_token_hash) {
            $setting->rotatePublicToken();
        }

        return $setting;
    }

    public function rotatePublicToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->forceFill([
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
        ])->save();
    }
}