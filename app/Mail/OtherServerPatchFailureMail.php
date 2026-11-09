<?php

namespace App\Mail;

use App\Models\OtherServerPatchRun;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

class OtherServerPatchFailureMail extends Mailable
{
    public function __construct(public OtherServerPatchRun $run)
    {
    }

    public function build(): self
    {
        $body = "ServerDashboard dry-run failure #{$this->run->id}\n"
            . "Server: {$this->run->server_name}\n"
            . "Instance: {$this->run->instance_id}\n"
            . "Account: " . ($this->run->account_id ?? 'Not verified') . "\n"
            . "Region: {$this->run->region}\n"
            . "SSM command: " . ($this->run->command_id ?? 'Not submitted / outcome unknown') . "\n\n"
            . "Detected error:\n{$this->run->error}\n\n"
            . "Next steps: inspect the saved dry-run result on Other Servers and the AWS SSM invocation. "
            . "For service failures, check systemctl status / journalctl -u on Linux or Get-Service / Event Viewer on Windows, "
            . "then validate dependencies and restore known-good configuration using the availability runbook. "
            . "For AWS failures, verify the account/region, role trust, Inspector ListFindings, and SSM permissions. "
            . "These are diagnostic steps, not a confirmed root cause or an automatic repair.\n\n"
            . "No packages were installed and no reboot or service restart was requested. "
            . "Live installation remains blocked until a tested zero-downtime runbook exists.";

        return $this->subject('ServerDashboard dry-run failure: ' . Str::squish($this->run->server_name))
            ->html(nl2br(e($body)));
    }
}