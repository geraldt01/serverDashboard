<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MonthlyItSecurityReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $reportUrl, public string $periodLabel)
    {
    }

    public function build(): self
    {
        return $this->subject("Monthly IT and Security Report - {$this->periodLabel}")
            ->view('emails.monthly-it-security-report');
    }
}