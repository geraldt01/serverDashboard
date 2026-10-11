<?php

namespace App\Console\Commands;

use App\Services\UptimeMonitor;
use Illuminate\Console\Command;

class RunUptimeChecks extends Command
{
    protected $signature = 'uptime:check';

    protected $description = 'Record availability checks for active WordPress sites and EC2 instances.';

    public function handle(UptimeMonitor $monitor): int
    {
        $result = $monitor->run();
        $this->info("Recorded {$result['checked']} uptime checks; {$result['failed']} checks failed or could not be completed.");

        return self::SUCCESS;
    }
}