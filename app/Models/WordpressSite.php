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
        'ip_whitelist',
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

    public function coreUpdates()
    {
        return $this->hasMany(WordpressCoreUpdate::class);
    }

    public function loginEvents()
    {
        return $this->hasMany(WordpressLoginEvent::class);
    }

    /**
     * @return array<int, string>
     */
    public function whitelistedIps(): array
    {
        return collect(preg_split('/[\r\n,]+/', (string) $this->ip_whitelist))
            ->map(fn ($ip) => trim($ip))
            ->filter()
            ->values()
            ->all();
    }

    public function isIpWhitelisted(string $ip): bool
    {
        $whitelist = $this->whitelistedIps();

        if ($whitelist === []) {
            return true;
        }

        foreach ($whitelist as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->ipMatchesCidr($ip, $entry)) {
                    return true;
                }
            } elseif (hash_equals($entry, $ip)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || $bits === null || ! is_numeric($bits)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
