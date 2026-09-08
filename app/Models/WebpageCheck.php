<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebpageCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'is_active',
        'required_elements',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'issues' => 'array',
        'last_checked_at' => 'datetime',
    ];

    /**
     * @return array<int, string>
     */
    public function requiredElementsList(): array
    {
        return collect(preg_split('/[\r\n,]+/', (string) $this->required_elements))
            ->map(fn ($selector) => trim($selector))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Persist the outcome of a WebpageHealthChecker::check() run onto this record.
     *
     * @param array{status:string,http_status:?int,response_time_ms:?int,broken_images:array,broken_videos:array,missing_elements:array,error:?string} $result
     */
    public function applyCheckResult(array $result): void
    {
        $issues = [];

        foreach ($result['broken_images'] as $image) {
            $issues[] = "Broken image: {$image['src']} ({$image['reason']})";
        }

        foreach ($result['broken_videos'] as $video) {
            $issues[] = "Broken video: {$video['src']} ({$video['reason']})";
        }

        foreach ($result['missing_elements'] as $missing) {
            $issues[] = "Missing element: {$missing}";
        }

        if ($result['error']) {
            $issues[] = $result['error'];
        }

        // forceFill(): these are computed/internal fields, not user input, and aren't (and
        // shouldn't be) in $fillable — a plain update() here silently drops them instead of
        // persisting, since $fillable is non-empty so Eloquent doesn't throw to warn us.
        $this->forceFill([
            'last_status' => $result['error'] ? 'broken' : $result['status'],
            'last_http_status' => $result['http_status'],
            'last_response_time_ms' => $result['response_time_ms'],
            'broken_images_count' => count($result['broken_images']),
            'broken_videos_count' => count($result['broken_videos']),
            'missing_elements_count' => count($result['missing_elements']),
            'issues' => $issues,
            'last_error' => $result['error'],
            'last_checked_at' => now(),
        ])->save();
    }
}
