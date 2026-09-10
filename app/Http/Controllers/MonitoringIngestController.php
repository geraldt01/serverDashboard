<?php

namespace App\Http\Controllers;

use App\Models\Ec2PatchStatus;
use App\Models\TrafficEvent;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use App\Services\WebpageHealthChecker;
use Aws\Ec2\Ec2Client;
use Aws\Ssm\SsmClient;
use GuzzleHttp\Client;
use Illuminate\Http\Request;

class MonitoringIngestController extends Controller
{
    public function traffic(Request $request)
    {
        $validated = $request->validate([
            'siteName' => ['required', 'string', 'min:2', 'max:120'],
            'visits' => ['required', 'integer', 'min:0', 'max:10000000'],
            'recordedAt' => ['nullable', 'date'],
        ]);

        TrafficEvent::create([
            'site_name' => $validated['siteName'],
            'visits' => $validated['visits'],
            'recorded_at' => $validated['recordedAt'] ?? now(),
        ]);

        return response()->json(['message' => 'Traffic metric saved.'], 201);
    }

    public function wordpress(Request $request)
    {
        $validated = $request->validate([
            'siteName' => ['required', 'string', 'min:2', 'max:120'],
            'plugins' => ['required', 'array', 'min:1', 'max:500'],
            'plugins.*.pluginName' => ['required', 'string', 'max:255'],
            'plugins.*.currentVersion' => ['required', 'string', 'max:80'],
            'plugins.*.latestVersion' => ['required', 'string', 'max:80'],
            'plugins.*.status' => ['required', 'in:up_to_date,outdated,unknown'],
        ]);

        $records = collect($validated['plugins'])->map(fn (array $plugin) => [
            'site_name' => $validated['siteName'],
            'plugin_name' => $plugin['pluginName'],
            'current_version' => $plugin['currentVersion'],
            'latest_version' => $plugin['latestVersion'],
            'status' => $plugin['status'],
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        WordpressPluginUpdate::insert($records);

        return response()->json(['message' => 'Plugin statuses saved.', 'inserted' => count($records)], 201);
    }

    public function syncEc2()
    {
        $records = $this->fetchEc2PatchStatuses();

        foreach ($records as $record) {
            Ec2PatchStatus::create($record);
        }

        $patchMessage = $this->triggerEc2PatchInstall(array_column($records, 'instance_id'));
        $wordpressMessage = $this->triggerWordpressReports();

        return back()->with('status', trim(sprintf(
            'EC2 sync complete: %d instance records saved. %s %s',
            count($records),
            $patchMessage,
            $wordpressMessage
        )));
    }

    /**
     * Actually install pending OS patches (not just read compliance status) on every EC2
     * instance discovered by fetchEc2PatchStatuses(), via AWS Systems Manager Run Command.
     * Uses the AWS-provided AWS-RunPatchBaseline document (Operation=Install) so it works
     * across Linux and Windows without the dashboard needing to know each instance's OS.
     * This can trigger reboots on instances whose patch baseline requires one.
     */
    private function triggerEc2PatchInstall(array $instanceIds): string
    {
        if (config('services.monitoring.mock_mode')) {
            return '(Mock mode) No real patch install was triggered.';
        }

        $instanceIds = array_values(array_unique(array_filter($instanceIds)));

        if (empty($instanceIds)) {
            return 'No EC2 instances available to patch.';
        }

        try {
            $ssm = new SsmClient(['version' => 'latest', 'region' => config('services.aws.region')]);
            $triggered = 0;

            foreach (array_chunk($instanceIds, 50) as $chunk) {
                $ssm->sendCommand([
                    'InstanceIds' => $chunk,
                    'DocumentName' => 'AWS-RunPatchBaseline',
                    'Comment' => 'ServerDashboard: Sync EC2 updates (install pending patches)',
                    'Parameters' => ['Operation' => ['Install']],
                    'TimeoutSeconds' => 600,
                ]);
                $triggered += count($chunk);
            }

            return sprintf('Patch install triggered on %d instance(s) via AWS SSM (may take several minutes; some instances may reboot).', $triggered);
        } catch (\Throwable $exception) {
            report($exception);

            return 'Failed to trigger patch install on EC2 instances: ' . $exception->getMessage();
        }
    }

    /**
     * Asks every active WordPress site to send an immediate report instead of waiting for
     * its own 6-hourly cron, by calling the reporter plugin's trigger-report REST route.
     * Signed the same way WordPress signs its own outbound reports (HMAC over
     * timestamp.nonce.body with the shared site token), just in the reverse direction.
     */
    private function triggerWordpressReports(): string
    {
        $sites = WordpressSite::query()->where('is_active', true)->get();

        if ($sites->isEmpty()) {
            return 'No active WordPress sites to notify.';
        }

        $checker = app(WebpageHealthChecker::class);
        $client = new Client(['timeout' => 10, 'connect_timeout' => 5]);
        $notified = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $endpoint = rtrim((string) $site->url, '/') . '/wp-json/serverdashboard/v1/trigger-report';
            $token = $site->monitoringToken();

            if ($token === '' || ! $checker->isUrlSafeToFetch($endpoint)) {
                $failed++;
                continue;
            }

            $body = '{}';
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $token);

            try {
                $response = $client->post($endpoint, [
                    'body' => $body,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-ServerDashboard-Timestamp' => $timestamp,
                        'X-ServerDashboard-Nonce' => $nonce,
                        'X-ServerDashboard-Signature' => $signature,
                    ],
                    'http_errors' => false,
                ]);

                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $notified++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return sprintf('WordPress report requested from %d site(s)%s.', $notified, $failed > 0 ? sprintf(' (%d failed)', $failed) : '');
    }

    private function fetchEc2PatchStatuses(): array
    {
        if (config('services.monitoring.mock_mode')) {
            return $this->mockEc2PatchStatuses();
        }

        try {
            $region = config('services.aws.region');
            $ec2 = new Ec2Client(['version' => 'latest', 'region' => $region]);
            $ssm = new SsmClient(['version' => 'latest', 'region' => $region]);
            $result = $ec2->describeInstances();
            $names = [];
            $instanceIds = [];

            foreach ($result['Reservations'] as $reservation) {
                foreach ($reservation['Instances'] as $instance) {
                    $instanceId = $instance['InstanceId'];
                    $instanceIds[] = $instanceId;
                    $nameTag = collect($instance['Tags'] ?? [])->firstWhere('Key', 'Name');
                    $names[$instanceId] = $nameTag['Value'] ?? $instanceId;
                }
            }

            $records = [];
            foreach (array_chunk($instanceIds, 50) as $chunk) {
                $patchStates = $ssm->describeInstancePatchStates(['InstanceIds' => $chunk]);
                $instanceInfo = $ssm->describeInstanceInformation([
                    'Filters' => [['Key' => 'InstanceIds', 'Values' => $chunk]],
                ]);
                $osVersions = [];
                foreach ($instanceInfo['InstanceInformationList'] as $info) {
                    $osVersions[$info['InstanceId']] = trim(($info['PlatformName'] ?? '') . ' ' . ($info['PlatformVersion'] ?? ''));
                }

                foreach ($patchStates['InstancePatchStates'] as $state) {
                    $records[] = [
                        'instance_id' => $state['InstanceId'],
                        'instance_name' => $names[$state['InstanceId']] ?? $state['InstanceId'],
                        'missing_count' => $state['MissingCount'] ?? 0,
                        'security_count' => $state['SecurityNonCompliantCount'] ?? 0,
                        'installed_count' => $state['InstalledCount'] ?? 0,
                        'failed_count' => $state['FailedCount'] ?? 0,
                        'reboot_required' => ($state['MissingCount'] ?? 0) > 0 && ($state['RebootOption'] ?? '') !== 'NoReboot',
                        'os_version' => $osVersions[$state['InstanceId']] ?: null,
                        'checked_at' => now(),
                    ];
                }
            }

            return $records;
        } catch (\Throwable $exception) {
            report($exception);
            return $this->mockEc2PatchStatuses();
        }
    }

    private function mockEc2PatchStatuses(): array
    {
        return [];
    }
}
