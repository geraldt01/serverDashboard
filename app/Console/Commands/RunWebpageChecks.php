<?php

namespace App\Console\Commands;

use App\Models\WebpageCheck;
use App\Services\WebpageHealthChecker;
use Illuminate\Console\Command;

class RunWebpageChecks extends Command
{
    protected $signature = 'webpage-checks:run';

    protected $description = 'Run the frontend health check for every active registered webpage.';

    public function handle(WebpageHealthChecker $checker): int
    {
        $checks = WebpageCheck::query()->where('is_active', true)->get();

        foreach ($checks as $webpageCheck) {
            $webpageCheck->applyCheckResult(
                $checker->check($webpageCheck->url, $webpageCheck->requiredElementsList())
            );

            $this->info("{$webpageCheck->name}: {$webpageCheck->last_status}");
        }

        return self::SUCCESS;
    }
}
