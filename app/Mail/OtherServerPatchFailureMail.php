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
        $isInstall = $this->run->install_requested_by !== null;
        $operation = $isInstall ? 'patch installation' : 'dry run';
        $outcome = $this->run->install_command_id
            ? "Patch Manager command {$this->run->install_command_id} was submitted. Patches may have been partially applied, services may have restarted, and the instance may have rebooted. Do not retry until you inspect Patch Manager / SSM command history and verify server health.\n"
            : ($isInstall
                ? 'An installation request was queued, but no Patch Manager command ID was saved. Submission outcome may be unknown. Inspect SSM Run Command history before retrying; the instance may have started patching or rebooted.\n'
                : 'No packages were installed, and no reboot or service restart was requested.\n');
        $body = "ServerDashboard {$operation} failure #{$this->run->id}\n"
            . "Server: {$this->run->server_name}\n"
            . "Instance: {$this->run->instance_id}\n"
            . "Account: " . ($this->run->account_id ?? 'Not verified') . "\n"
            . "Region: {$this->run->region}\n"
            . "Dry-run SSM command: " . ($this->run->command_id ?? 'Not submitted') . "\n"
            . "Patch Manager command: " . ($this->run->install_command_id ?? ($isInstall ? 'Not confirmed / outcome unknown' : 'Not requested')) . "\n"
            . "Post-install service-check command: " . ($this->run->health_check_command_id ?? 'Not submitted') . "\n\n"
            . "Detected error:\n{$this->run->error}\n\n"
            . "Next steps: inspect the saved dry-run result on Other Servers and the AWS SSM invocation. "
            . "For service failures, check systemctl status / journalctl -u on Linux or Get-Service / Event Viewer on Windows, "
            . "then validate dependencies and restore known-good configuration using the availability runbook. "
            . "For AWS failures, verify the account/region, role trust, Inspector ListFindings, and SSM permissions. "
            . "These are diagnostic steps, not a confirmed root cause or an automatic repair.\n\n"
            . $outcome;

        return $this->subject('ServerDashboard ' . $operation . ' failure: ' . Str::squish($this->run->server_name))
            ->html(nl2br(e($body)));
    }
}