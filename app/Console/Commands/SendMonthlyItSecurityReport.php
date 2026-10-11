<?php

namespace App\Console\Commands;

use App\Mail\MonthlyItSecurityReportMail;
use App\Models\MonthlyReportSetting;
use App\Services\MonthlyItSecurityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendMonthlyItSecurityReport extends Command
{
    protected $signature = 'reports:send-monthly';

    protected $description = 'Email the previous month IT and security report to the configured manager.';

    public function handle(MonthlyItSecurityReport $report): int
    {
        $settings = MonthlyReportSetting::current();
        if (! $settings->manager_email) {
            $this->error('Set the manager email under Reporting Settings before enabling monthly delivery.');

            return self::FAILURE;
        }

        $period = $report->period();
        $url = route('monthly-reports.public', ['token' => $settings->public_token]);
        Mail::to($settings->manager_email)->send(new MonthlyItSecurityReportMail($url, $period['label']));
        $settings->forceFill(['last_sent_at' => now()])->save();
        $this->info("Monthly IT and Security report emailed for {$period['label']}.");

        return self::SUCCESS;
    }
}