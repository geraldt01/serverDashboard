<?php

namespace App\Services;

use App\Mail\OtherServerPatchFailureMail;
use App\Models\OtherServer;
use App\Models\OtherServerPatchRun;
use Aws\Inspector2\Inspector2Client;
use Aws\Ssm\Exception\SsmException;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class OtherServerPatchDryRun
{
    public function __construct(private OtherServerAwsClients $clients, private OtherServerDryRunScript $scripts)
    {
    }

    public function enqueue(OtherServer $server): OtherServerPatchRun
    {
        if (config('services.monitoring.mock_mode')) {
            throw new \RuntimeException('Dry runs are disabled in mock mode. No AWS access or service health has been verified.');
        }
        if (! $server->is_active || ! preg_match('/\Ai-[0-9a-f]{8,17}\z/', (string) $server->aws_instance_id)) {
            throw new \RuntimeException('An active server with a valid AWS instance ID is required.');
        }
        $email = trim((string) config('services.patching.alert_email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Set a valid PATCH_ALERT_EMAIL and verify production mail delivery before starting a dry run.');
        }
        $services = $server->criticalServicesList();
        $this->scripts->build('Linux', $services, [], 0);
        $region = (string) config('services.aws.region');
        $target = $region . '|' . $server->aws_instance_id;

        try {
            return OtherServerPatchRun::create([
                'other_server_id' => $server->id,
                'server_name' => $server->name,
                'instance_id' => $server->aws_instance_id,
                'region' => $region,
                'role_arn' => $server->aws_role_arn,
                'active_target' => $target,
                'status' => 'queued',
                'alert_email' => $email,
                'services' => $services,
                'deadline_at' => now()->addMinutes(20),
            ]);
        } catch (QueryException $exception) {
            if (OtherServerPatchRun::where('active_target', $target)->exists()) {
                throw new \RuntimeException('A dry run is already active for this instance and region. Wait for its result; no additional command was submitted.');
            }
            throw $exception;
        }
    }

    public function requestInstall(OtherServerPatchRun $run, int $userId): void
    {
        if (config('services.monitoring.mock_mode')) {
            throw new \RuntimeException('Patch installation is disabled in mock mode.');
        }
        if (! $run->canInstall() || $run->completed_at?->lt(now()->subDay())) {
            throw new \RuntimeException('Installation requires a recent passed dry run with fixable Inspector package findings. Run a fresh dry run first.');
        }

        $target = $run->region . '|' . $run->instance_id;

        try {
            $claimed = OtherServerPatchRun::query()
                ->whereKey($run->id)
                ->where('status', 'dry_run_passed')
                ->whereNull('active_target')
                ->update([
                    'status' => 'install_queued',
                    'active_target' => $target,
                    'install_requested_by' => $userId,
                    'install_requested_at' => now(),
                    'deadline_at' => now()->addMinutes(90),
                    'error' => null,
                    'alert_error' => null,
                ]);
        } catch (QueryException $exception) {
            if (OtherServerPatchRun::where('active_target', $target)->exists()) {
                throw new \RuntimeException('Another dry run or installation is already active for this instance and region. No additional command was submitted.');
            }
            throw $exception;
        }

        if (! $claimed) {
            throw new \RuntimeException('This run is no longer eligible for installation. Refresh the page and use a recent passed dry run.');
        }
    }

    public function advance(OtherServerPatchRun $run): void
    {
        Cache::lock('other-server-dry-run-' . $run->id, 120)->get(function () use ($run) {
            $run->refresh();
            if (! in_array($run->status, ['queued', 'preparing', 'running', 'install_queued', 'install_submitting', 'installing', 'post_checking'], true)) {
                return;
            }
            if ($run->deadline_at->isPast()) {
                $message = $run->install_command_id
                    ? 'Patch installation or its post-install service checks exceeded 90 minutes. Patches may have been partially applied and the instance may have rebooted. Inspect SSM Run Command and Patch Manager history before retrying.'
                    : ($run->status === 'install_submitting'
                        ? 'Patch command submission outcome is unknown. Check SSM Run Command history before retrying; the instance may have started patching or rebooted.'
                        : 'Dry-run deadline exceeded. Check the Laravel scheduler, SSM Agent, outbound HTTPS, and Run Command invocation status. No installation was requested.');
                $this->fail($run, $message);
                return;
            }
            if (config('services.monitoring.mock_mode')) {
                if (! in_array($run->status, ['installing', 'post_checking'], true)) {
                    $this->fail($run, 'Mock mode is enabled. Disable AWS_SYNC_USE_MOCK and repeat the operation; no health result can be verified.');
                    return;
                }
            }
            if ($run->status === 'install_submitting') {
                $this->fail($run, 'Patch command submission outcome is unknown. Check SSM Run Command history before retrying; the instance may have started patching or rebooted.');
                return;
            }
            try {
                $args = array_merge($this->clients->arguments($run->target(), $run->region), [
                    'retries' => 0,
                    'http' => ['connect_timeout' => 3, 'timeout' => 10],
                ]);
                $ssm = app(SsmClient::class, ['args' => $args]);
                if ($run->status === 'queued') {
                    $claimed = OtherServerPatchRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'preparing']);
                    if ($claimed) {
                        $this->prepare($run, $args, $ssm);
                    }
                } elseif ($run->status === 'running') {
                    $this->poll($run, $ssm);
                } elseif ($run->status === 'install_queued') {
                    $claimed = OtherServerPatchRun::whereKey($run->id)->where('status', 'install_queued')->update(['status' => 'install_submitting']);
                    if ($claimed) {
                        $this->startInstallation($run, $args, $ssm);
                    }
                } elseif ($run->status === 'installing') {
                    $this->pollInstallation($run, $ssm);
                } elseif ($run->status === 'post_checking') {
                    $this->pollPostInstallCheck($run, $ssm);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $this->fail($run, $exception->getMessage());
            }
        });
    }

    private function prepare(OtherServerPatchRun $run, array $args, SsmClient $ssm): void
    {
        $identity = app(StsClient::class, ['args' => $args])->getCallerIdentity();
        $account = (string) ($identity['Account'] ?? '');
        if (! preg_match('/\A[0-9]{12}\z/', $account)) {
            throw new \RuntimeException('AWS account identity could not be verified. Check STS access and role trust; no command was submitted.');
        }
        $run->update(['account_id' => $account]);
        $information = $ssm->describeInstanceInformation([
            'Filters' => [['Key' => 'InstanceIds', 'Values' => [$run->instance_id]]],
        ]);
        $instance = collect($information['InstanceInformationList'] ?? [])->firstWhere('InstanceId', $run->instance_id);
        if (($instance['PingStatus'] ?? null) !== 'Online') {
            throw new \RuntimeException('The selected instance is not Online in Systems Manager. Verify the instance/account/region, SSM Agent, IAM instance profile and outbound HTTPS. No command was submitted.');
        }
        $platform = (string) ($instance['PlatformType'] ?? '');
        $inspector = app(Inspector2Client::class, ['args' => $args]);
        $findings = [];
        $packages = [];
        $nextToken = null;
        $page = 0;
        do {
            $params = [
                'filterCriteria' => [
                    'resourceId' => [['comparison' => 'EQUALS', 'value' => $run->instance_id]],
                    'awsAccountId' => [['comparison' => 'EQUALS', 'value' => $account]],
                    'findingStatus' => [['comparison' => 'EQUALS', 'value' => 'ACTIVE']],
                    'findingType' => [
                        ['comparison' => 'EQUALS', 'value' => 'PACKAGE_VULNERABILITY'],
                        ['comparison' => 'EQUALS', 'value' => 'CODE_VULNERABILITY'],
                    ],
                ],
                'maxResults' => 100,
            ];
            if ($nextToken !== null) {
                $params['nextToken'] = $nextToken;
            }
            $result = $inspector->listFindings($params);
            foreach ($result['findings'] ?? [] as $finding) {
                $matches = collect($finding['resources'] ?? [])->contains(fn ($resource) =>
                    ($resource['id'] ?? null) === $run->instance_id
                    && ($resource['type'] ?? null) === 'AWS_EC2_INSTANCE'
                    && ($resource['region'] ?? null) === $run->region
                    && ($resource['providerAccountId'] ?? $finding['awsAccountId'] ?? null) === $account);
                if (! $matches || ($finding['status'] ?? null) !== 'ACTIVE' || ($finding['awsAccountId'] ?? null) !== $account) {
                    continue;
                }
                $details = [];
                foreach ($finding['packageVulnerabilityDetails']['vulnerablePackages'] ?? [] as $package) {
                    $name = (string) ($package['name'] ?? '');
                    $fixed = $package['fixedInVersion'] ?? null;
                    $preview = $platform === 'Linux'
                        && ($finding['type'] ?? null) === 'PACKAGE_VULNERABILITY'
                        && in_array($finding['fixAvailable'] ?? null, ['YES', 'PARTIAL'], true)
                        && ($package['packageManager'] ?? null) === 'DEB'
                        && is_string($fixed) && $fixed !== '' && $fixed !== 'NotAvailable'
                        && preg_match('/\A[a-z0-9][a-z0-9+.-]{0,127}(?::[a-z0-9]+)?\z/', $name);
                    if ($preview) {
                        $packages[$name] = $name;
                    }
                    $details[] = [
                        'name' => $name,
                        'installed' => $package['version'] ?? null,
                        'fixed' => $fixed,
                        'manager' => $package['packageManager'] ?? null,
                        'preview' => (bool) $preview,
                    ];
                }
                $findings[] = [
                    'arn' => $finding['findingArn'] ?? '',
                    'title' => $finding['title'] ?? 'Untitled finding',
                    'severity' => $finding['severity'] ?? 'UNTRIAGED',
                    'type' => $finding['type'] ?? '',
                    'fix' => $finding['fixAvailable'] ?? 'NOT_REPORTED',
                    'packages' => $details,
                ];
            }
            $nextToken = $result['nextToken'] ?? null;
            if (++$page >= 10 && $nextToken) {
                throw new \RuntimeException('Inspector results exceed the dry-run limit. Narrow the remediation scope; no command was submitted.');
            }
        } while ($nextToken);
        $packages = array_values($packages);
        sort($packages);
        $script = $this->scripts->build($platform, $run->services, $packages, $run->id);
        $run->update([
            'account_id' => $account,
            'plan' => ['platform' => $platform, 'findings' => $findings, 'packages' => $packages, 'captured_at' => now()->toIso8601String()],
        ]);
        $result = $ssm->sendCommand([
            'InstanceIds' => [$run->instance_id],
            'DocumentName' => $script['document'],
            'Comment' => 'ServerDashboard read-only dry run #' . $run->id,
            'Parameters' => ['commands' => [$script['script']], 'executionTimeout' => ['300']],
            'TimeoutSeconds' => 60,
        ]);
        $commandId = $result['Command']['CommandId'] ?? null;
        if (! is_string($commandId) || $commandId === '') {
            throw new \RuntimeException('SSM submission returned no command ID. Inspect AWS Run Command history before retrying; the submission outcome is unknown.');
        }
        $run->update(['command_id' => $commandId, 'status' => 'running']);
    }

    private function poll(OtherServerPatchRun $run, SsmClient $ssm): void
    {
        try {
            $result = $ssm->getCommandInvocation(['CommandId' => $run->command_id, 'InstanceId' => $run->instance_id]);
        } catch (SsmException $exception) {
            if ($exception->getAwsErrorCode() === 'InvocationDoesNotExist') {
                return;
            }
            throw $exception;
        }
        $status = $result['Status'] ?? '';
        if (in_array($status, ['Pending', 'InProgress', 'Delayed', 'Cancelling'], true)) {
            return;
        }
        $output = (string) ($result['StandardOutputContent'] ?? '');
        $run->update(['output' => mb_substr($output . "\n" . ($result['StandardErrorContent'] ?? ''), 0, 24000)]);
        try {
            $this->scripts->verify($output, $run->services, $run->id);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException("SSM invocation status {$status}: {$exception->getMessage()}", 0, $exception);
        }
        if ($status !== 'Success' || ($result['ResponseCode'] ?? -1) !== 0) {
            throw new \RuntimeException("SSM dry-run invocation ended with status {$status}. Inspect Run Command output, SSM Agent logs and package simulation errors before retrying. No installation was requested.");
        }
        $run->update(['status' => 'dry_run_passed', 'completed_at' => now(), 'active_target' => null]);
    }

    private function startInstallation(OtherServerPatchRun $run, array $args, SsmClient $ssm): void
    {
        if (! $run->hasInstallableFindings() || $run->completed_at?->lt(now()->subDay())) {
            throw new \RuntimeException('The dry-run result is no longer eligible. Run a fresh dry run before installing.');
        }

        $identity = app(StsClient::class, ['args' => $args])->getCallerIdentity();
        if (($identity['Account'] ?? null) !== $run->account_id) {
            throw new \RuntimeException('AWS account identity changed since the dry run. No patch command was submitted.');
        }
        $information = $ssm->describeInstanceInformation([
            'Filters' => [['Key' => 'InstanceIds', 'Values' => [$run->instance_id]]],
        ]);
        $instance = collect($information['InstanceInformationList'] ?? [])->firstWhere('InstanceId', $run->instance_id);
        if (($instance['PingStatus'] ?? null) !== 'Online') {
            throw new \RuntimeException('The instance is not Online in Systems Manager. No patch command was submitted. Verify the instance and SSM Agent, then run a new dry run.');
        }
        if (($instance['PlatformType'] ?? null) !== ($run->plan['platform'] ?? null)) {
            throw new \RuntimeException('The instance platform changed since the dry run. No patch command was submitted; run a fresh dry run.');
        }

        $result = $ssm->sendCommand([
            'InstanceIds' => [$run->instance_id],
            'DocumentName' => 'AWS-RunPatchBaseline',
            'Comment' => 'ServerDashboard confirmed baseline installation for dry-run #' . $run->id,
            'Parameters' => [
                'Operation' => ['Install'],
                'RebootOption' => ['RebootIfNeeded'],
            ],
            'TimeoutSeconds' => 3600,
        ]);
        $commandId = $result['Command']['CommandId'] ?? null;
        if (! is_string($commandId) || $commandId === '') {
            throw new \RuntimeException('Patch Manager returned no command ID. Submission outcome is unknown; inspect SSM history before retrying.');
        }
        $run->update(['install_command_id' => $commandId, 'status' => 'installing']);
    }

    private function pollInstallation(OtherServerPatchRun $run, SsmClient $ssm): void
    {
        $result = $this->commandInvocation($ssm, $run->install_command_id, $run);
        if ($result === null) {
            return;
        }
        $status = $result['Status'] ?? '';
        if (in_array($status, ['Pending', 'InProgress', 'Delayed', 'Cancelling'], true)) {
            return;
        }
        $output = (string) ($result['StandardOutputContent'] ?? '');
        $run->update(['install_output' => mb_substr($output . "\n" . ($result['StandardErrorContent'] ?? ''), 0, 24000)]);
        if ($status !== 'Success' || ($result['ResponseCode'] ?? -1) !== 0) {
            throw new \RuntimeException("Patch Manager installation ended with status {$status}. Packages may have been partially applied and the instance may have rebooted. Inspect Patch Manager command output and instance health before retrying.");
        }

        $check = $this->scripts->build($run->plan['platform'], $run->services, [], $run->id);
        $health = $ssm->sendCommand([
            'InstanceIds' => [$run->instance_id],
            'DocumentName' => $check['document'],
            'Comment' => 'ServerDashboard post-install critical-service check for run #' . $run->id,
            'Parameters' => ['commands' => [$check['script']], 'executionTimeout' => ['300']],
            'TimeoutSeconds' => 60,
        ]);
        $healthCommandId = $health['Command']['CommandId'] ?? null;
        if (! is_string($healthCommandId) || $healthCommandId === '') {
            throw new \RuntimeException('Patch installation succeeded but SSM returned no post-install check command ID. Inspect instance health and Patch Manager history; the service check did not run.');
        }
        $run->update(['health_check_command_id' => $healthCommandId, 'status' => 'post_checking']);
    }

    private function pollPostInstallCheck(OtherServerPatchRun $run, SsmClient $ssm): void
    {
        $result = $this->commandInvocation($ssm, $run->health_check_command_id, $run);
        if ($result === null) {
            return;
        }
        $status = $result['Status'] ?? '';
        if (in_array($status, ['Pending', 'InProgress', 'Delayed', 'Cancelling'], true)) {
            return;
        }
        $output = (string) ($result['StandardOutputContent'] ?? '');
        $run->update(['output' => mb_substr((string) $run->output . "\nPost-install checks:\n" . $output . "\n" . ($result['StandardErrorContent'] ?? ''), 0, 24000)]);
        try {
            $this->scripts->verify($output, $run->services, $run->id);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException("Patch Manager installation succeeded, but SSM service check status was {$status}: {$exception->getMessage()} Installed packages may have restarted services or the instance may have rebooted. Inspect the application and service logs.", 0, $exception);
        }
        if ($status !== 'Success' || ($result['ResponseCode'] ?? -1) !== 0) {
            throw new \RuntimeException("Patch Manager installation succeeded, but post-install checks ended with status {$status}. Inspect the application and service logs.");
        }
        $run->update(['status' => 'install_health_passed', 'completed_at' => now(), 'active_target' => null]);
    }

    private function commandInvocation(SsmClient $ssm, ?string $commandId, OtherServerPatchRun $run): ?array
    {
        if (! is_string($commandId) || $commandId === '') {
            throw new \RuntimeException('SSM command ID is missing. Inspect Run Command history before retrying.');
        }
        try {
            return $ssm->getCommandInvocation(['CommandId' => $commandId, 'InstanceId' => $run->instance_id])->toArray();
        } catch (SsmException $exception) {
            if ($exception->getAwsErrorCode() === 'InvocationDoesNotExist') {
                return null;
            }
            throw $exception;
        }
    }

    private function fail(OtherServerPatchRun $run, string $message): void
    {
        $run->update([
            'status' => 'failed',
            'error' => mb_substr($message, 0, 8000),
            'completed_at' => now(),
            'active_target' => null,
        ]);
    }

    public function notifyFailure(OtherServerPatchRun $run): void
    {
        Cache::lock('other-server-dry-run-' . $run->id, 120)->get(function () use ($run) {
            $run->refresh();
            if ($run->status !== 'failed' || $run->alert_sent_at !== null) {
                return;
            }
            try {
                Mail::to($run->alert_email)->send(new OtherServerPatchFailureMail($run));
                $run->update(['alert_sent_at' => now(), 'alert_error' => null]);
            } catch (\Throwable $exception) {
                report($exception);
                $run->update(['alert_error' => 'Mail delivery failed. Verify the production mail transport; the scheduler will retry.']);
            }
        });
    }
}