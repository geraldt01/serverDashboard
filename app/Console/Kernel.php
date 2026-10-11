<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('webpage-checks:run')->hourly()->withoutOverlapping();
        $schedule->command('uptime:check')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('ec2:collect-patch-status')->hourly()->withoutOverlapping();
        $schedule->command('reports:send-monthly')->monthlyOn(5, '08:00')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
