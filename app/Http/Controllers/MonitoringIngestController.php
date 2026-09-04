<?php

namespace App\Http\Controllers;

use App\Models\Ec2PatchStatus;
use App\Models\TrafficEvent;
use App\Models\WordpressPluginUpdate;
use Aws\Ec2\Ec2Client;
use Aws\Ssm\SsmClient;
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

        return back()->with('status', sprintf('EC2 sync complete: %d instance records saved.', count($records)));
    }

    private function fetchEc2PatchStatuses(): array
    {
        if (config('services.monitoring.mock_mode')) {
            return $this->mockEc2PatchStatuses();
        }

        try {
            $region = config('services.ses.region');
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
        return [
            [
                'instance_id' => 'i-0a1b2c3d4e5f001',
                'instance_name' => 'wp-prod-1',
                'missing_count' => 4,
                'security_count' => 3,
                'installed_count' => 112,
                'failed_count' => 0,
                'reboot_required' => true,
                'os_version' => 'Ubuntu 22.04',
                'checked_at' => now(),
            ],
            [
                'instance_id' => 'i-0a1b2c3d4e5f002',
                'instance_name' => 'api-prod-1',
                'missing_count' => 0,
                'security_count' => 0,
                'installed_count' => 97,
                'failed_count' => 0,
                'reboot_required' => false,
                'os_version' => 'Amazon Linux 2023',
                'checked_at' => now(),
            ],
        ];
    }
}
