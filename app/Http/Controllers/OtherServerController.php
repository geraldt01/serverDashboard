<?php

namespace App\Http\Controllers;

use App\Models\OtherServer;
use App\Models\OtherServerPatchReport;
use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Ssm\Exception\SsmException;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OtherServerController extends Controller
{
    public function index()
    {
        return view('other-servers.index', [
            'otherServers' => OtherServer::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'awsInstanceId' => ['nullable', 'string', 'regex:/^i-[0-9a-f]{8,17}$/'],
            'awsRoleArn' => ['nullable', 'string', 'max:2048', 'regex:~^arn:aws:iam::[0-9]{12}:role/(?:[A-Za-z0-9_+=,.@-]+/)*[A-Za-z0-9_+=,.@-]+$~'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'server';
        $slug = $baseSlug;
        $suffix = 2;

        while (OtherServer::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $suffix++;
        }

        $token = Str::random(48);
        $server = new OtherServer([
            'name' => $validated['name'],
            'slug' => $slug,
            'hostname' => $validated['hostname'] ?? null,
            'aws_instance_id' => $validated['awsInstanceId'] ?? null,
            'aws_role_arn' => $validated['awsRoleArn'] ?? null,
        ]);
        $server->setMonitoringToken($token);
        $server->save();

        return redirect()->route('other-servers.index')
            ->with('status', "Server {$server->name} added. Copy the generated credentials now; the token will not be shown again.")
            ->with('otherServerCredentials', $this->credentialsFor($server, $token));
    }

    public function destroy(OtherServer $otherServer)
    {
        $name = $otherServer->name;
        $otherServer->delete();

        return redirect()->route('other-servers.index')
            ->with('status', "Server {$name} and its reporting history were permanently removed. The actual server was not affected; stop or uninstall its reporting agent separately.");
    }

    public function rotateToken(OtherServer $otherServer)
    {
        $token = Str::random(48);
        $otherServer->setMonitoringToken($token);
        $otherServer->save();

        return redirect()->route('other-servers.index')
            ->with('status', "The token for {$otherServer->name} was rotated. Update the agent config now; the previous token no longer works.")
            ->with('otherServerCredentials', $this->credentialsFor($otherServer, $token));
    }

    public function toggleActive(OtherServer $otherServer)
    {
        $otherServer->update(['is_active' => ! $otherServer->is_active]);
        $state = $otherServer->is_active ? 'enabled' : 'disabled';

        return redirect()->route('other-servers.index')->with('status', "Reporting for {$otherServer->name} is {$state}.");
    }

    public function updateDetails(Request $request, OtherServer $otherServer)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'awsInstanceId' => ['nullable', 'string', 'regex:/^i-[0-9a-f]{8,17}$/'],
            'awsRoleArn' => ['nullable', 'string', 'max:2048', 'regex:~^arn:aws:iam::[0-9]{12}:role/(?:[A-Za-z0-9_+=,.@-]+/)*[A-Za-z0-9_+=,.@-]+$~'],
        ]);

        $otherServer->update([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'] ?? null,
            'aws_instance_id' => $validated['awsInstanceId'] ?? null,
            'aws_role_arn' => $request->exists('awsRoleArn')
                ? ($validated['awsRoleArn'] ?? null)
                : $otherServer->aws_role_arn,
        ]);

        return redirect()->route('other-servers.index')
            ->with('status', "Updated connection details for {$otherServer->name}.");
    }

    public function testConnection(OtherServer $otherServer)
    {
        $instanceId = trim((string) $otherServer->aws_instance_id);

        if ($instanceId === '') {
            return redirect()->route('other-servers.index')
                ->with('error', "Cannot test SSM access for {$otherServer->name}: no AWS instance ID is set for this server.");
        }

        if (config('services.monitoring.mock_mode')) {
            return redirect()->route('other-servers.index')
                ->with('status', "(Mock mode) SSM connection test simulated for {$otherServer->name}. No AWS access was verified.");
        }

        $region = config('services.aws.region');
        $awsIdentity = $otherServer->aws_role_arn
            ? "configured role {$otherServer->aws_role_arn}"
            : "the dashboard's AWS account";

        try {
            $ssm = $this->ssmClientFor($otherServer);
            $result = $ssm->describeInstanceInformation([
                'Filters' => [['Key' => 'InstanceIds', 'Values' => [$instanceId]]],
            ]);
            $instance = collect($result['InstanceInformationList'] ?? [])
                ->firstWhere('InstanceId', $instanceId);

            if ($instance === null) {
                return redirect()->route('other-servers.index')
                    ->with('error', "SSM connection test failed for {$otherServer->name}: instance {$instanceId} is not registered with Systems Manager in {$region} using {$awsIdentity}. Verify the instance ID, account and region, and the instance's SSM Agent and IAM role. A server in another AWS account requires cross-account role access; scheduled agent reports do not require it.");
            }

            $pingStatus = $instance['PingStatus'] ?? 'Unknown';

            if ($pingStatus !== 'Online') {
                return redirect()->route('other-servers.index')
                    ->with('error', "SSM connection test failed for {$otherServer->name} ({$instanceId}, {$region}): ping status is {$pingStatus}. Confirm the instance is running, the SSM Agent and IAM role are configured, and outbound HTTPS access to Systems Manager is available. No inbound SSH access is required.");
            }

            return redirect()->route('other-servers.index')
                ->with('status', "SSM connection test succeeded: {$otherServer->name} ({$instanceId}, {$region}) is Online in Systems Manager. No SSH connection was attempted. This read-only check does not verify SendCommand permission or the ServerDashboard reporting agent installation.");
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('other-servers.index')
                ->with('error', "SSM connection test failed for {$otherServer->name} ({$instanceId}, {$region}) using {$awsIdentity}. Verify AWS credentials, role trust and ssm:DescribeInstanceInformation permission: {$exception->getMessage()}");
        }
    }

    /**
     * Trigger an immediate patch/update check on the instance via AWS Systems Manager Run Command.
     * No SSH keys are stored by the dashboard; execution relies on the instance's own SSM Agent and IAM role.
     */
    public function patchNow(OtherServer $otherServer)
    {
        $instanceId = trim((string) $otherServer->aws_instance_id);

        if ($instanceId === '') {
            return redirect()->route('other-servers.index')
                ->with('status', "Cannot patch {$otherServer->name}: no AWS instance ID is set for this server.");
        }

        if (config('services.monitoring.mock_mode')) {
            $security = random_int(0, 5);
            $total = $security + random_int(0, 15);

            $otherServer->update([
                'os_name' => $otherServer->os_name ?: 'Ubuntu 24.04.4 LTS',
                'total_updates' => $total,
                'security_updates' => $security,
                'reboot_required' => $security > 0 && random_int(0, 3) === 0,
                'last_reported_at' => now(),
            ]);

            return redirect()->route('other-servers.index')
                ->with('status', "(Mock mode) Patch check triggered for {$otherServer->name}. Figures updated immediately (simulated push).");
        }

        try {
            $ssm = $this->ssmClientFor($otherServer);
            $result = $ssm->sendCommand([
                'InstanceIds' => [$instanceId],
                'DocumentName' => 'AWS-RunShellScript',
                'Comment' => "ServerDashboard manual patch check: {$otherServer->name}",
                'Parameters' => ['commands' => ['/usr/local/bin/serverdashboard-agent.sh']],
                'TimeoutSeconds' => 60,
            ]);

            $commandId = $result['Command']['CommandId'] ?? null;

            return redirect()->route('other-servers.index')
                ->with('status', "Patch check triggered for {$otherServer->name} via AWS SSM (command {$commandId}). Updated figures should appear within a minute.");
        } catch (\Throwable $exception) {
            report($exception);

            if ($exception instanceof SsmException && $exception->getAwsErrorCode() === 'InvalidInstanceId') {
                $region = config('services.aws.region');
                $awsIdentity = $otherServer->aws_role_arn
                    ? "configured role {$otherServer->aws_role_arn}"
                    : "the dashboard's AWS account";

                return redirect()->route('other-servers.index')
                    ->with('error', "Patch check could not run for {$otherServer->name}: AWS Systems Manager cannot target instance {$instanceId} in {$region} using {$awsIdentity}. The server remains registered. Verify the instance ID, AWS account and AWS_DEFAULT_REGION; confirm the instance is running and appears Online in Systems Manager Managed nodes in that account and region. Check that the SSM Agent is running, the instance IAM role includes AmazonSSMManagedInstanceCore, and outbound HTTPS access to Systems Manager is available. The ServerDashboard reporting agent must also be installed; its scheduled reports do not require SSM.");
            }

            return redirect()->route('other-servers.index')
                ->with('error', "Failed to trigger patch check for {$otherServer->name}: {$exception->getMessage()}");
        }
    }

    private function ssmClientFor(OtherServer $otherServer): SsmClient
    {
        $args = ['version' => 'latest', 'region' => config('services.aws.region')];
        $roleArn = trim((string) $otherServer->aws_role_arn);

        if ($roleArn !== '') {
            try {
                $provider = new AssumeRoleCredentialProvider([
                    'client' => app(StsClient::class, ['args' => $args]),
                    'assume_role_params' => [
                        'RoleArn' => $roleArn,
                        'RoleSessionName' => 'serverdashboard-' . $otherServer->id,
                        'DurationSeconds' => 900,
                    ],
                ]);
                $args['credentials'] = $provider()->wait();
            } catch (\Throwable $exception) {
                throw new \RuntimeException("Unable to assume AWS role {$roleArn}. Verify the dashboard's sts:AssumeRole permission and the target role's trust policy.", 0, $exception);
            }
        }

        return app(SsmClient::class, ['args' => $args]);
    }

    public function report(Request $request, OtherServer $otherServer)
    {
        $validated = $request->validate([
            'osName' => ['nullable', 'string', 'max:120'],
            'totalUpdates' => ['required', 'integer', 'min:0', 'max:100000'],
            'securityUpdates' => ['required', 'integer', 'min:0', 'max:100000', 'lte:totalUpdates'],
            'updateDetails' => ['nullable', 'string', 'max:8000'],
            'securityUpdateDetails' => ['nullable', 'string', 'max:8000'],
            'rebootRequired' => ['required', 'boolean'],
            'phpVersion' => ['nullable', 'string', 'max:40'],
            'phpUpdateAvailable' => ['nullable', 'boolean'],
            'checkedAt' => ['nullable', 'date'],
        ]);

        $reportedAt = $validated['checkedAt'] ?? now();
        $otherServer->update([
            'os_name' => $validated['osName'] ?? $otherServer->os_name,
            'total_updates' => $validated['totalUpdates'],
            'security_updates' => $validated['securityUpdates'],
            'update_details' => $request->exists('updateDetails')
                ? ($validated['updateDetails'] ?? null)
                : $otherServer->update_details,
            'security_update_details' => $request->exists('securityUpdateDetails')
                ? ($validated['securityUpdateDetails'] ?? null)
                : $otherServer->security_update_details,
            'reboot_required' => $validated['rebootRequired'],
            'php_version' => $validated['phpVersion'] ?? $otherServer->php_version,
            'php_update_available' => $validated['phpUpdateAvailable'] ?? false,
            'last_reported_at' => $reportedAt,
        ]);

        OtherServerPatchReport::create([
            'other_server_id' => $otherServer->id,
            'total_updates' => $validated['totalUpdates'],
            'security_updates' => $validated['securityUpdates'],
            'reboot_required' => $validated['rebootRequired'],
            'update_details' => $request->exists('updateDetails') ? ($validated['updateDetails'] ?? null) : $otherServer->update_details,
            'security_update_details' => $request->exists('securityUpdateDetails') ? ($validated['securityUpdateDetails'] ?? null) : $otherServer->security_update_details,
            'reported_at' => $reportedAt,
        ]);

        return response()->json(['message' => 'Server update report saved.'], 201);
    }

    private function credentialsFor(OtherServer $server, string $token): array
    {
        return [
            'serverName' => $server->name,
            'endpoint' => route('other-servers.report', ['otherServer' => $server->slug]),
            'token' => $token,
        ];
    }
}
