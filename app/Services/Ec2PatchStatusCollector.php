<?php

namespace App\Services;

use App\Models\Ec2PatchStatus;
use Aws\Ec2\Ec2Client;
use Aws\Ssm\SsmClient;
use Closure;
use Illuminate\Support\Facades\Log;

class Ec2PatchStatusCollector
{
    public function __construct(private readonly ?Closure $clientFactory = null)
    {
    }

    public function collect(): array
    {
        $baseRegion = config('services.aws.region');
        $ec2 = $this->client('ec2', $baseRegion);
        $regions = collect($ec2->describeRegions(['AllRegions' => false])['Regions'] ?? [])->pluck('RegionName')->filter()->values();
        $result = ['regions' => $regions->count(), 'instances' => 0, 'patch_snapshots' => 0, 'failed_regions' => []];

        foreach ($regions as $region) {
            try {
                $regionalEc2 = $this->client('ec2', $region);
                $names = [];
                $instanceIds = [];
                foreach ($regionalEc2->getPaginator('DescribeInstances', []) as $page) {
                    foreach ($page['Reservations'] ?? [] as $reservation) {
                        foreach ($reservation['Instances'] ?? [] as $instance) {
                            $instanceId = $instance['InstanceId'];
                            $instanceIds[] = $instanceId;
                            $nameTag = collect($instance['Tags'] ?? [])->firstWhere('Key', 'Name');
                            $names[$instanceId] = $nameTag['Value'] ?? $instanceId;
                        }
                    }
                }
                $result['instances'] += count($instanceIds);

                $ssm = $this->client('ssm', $region);
                $managedIds = [];
                foreach ($ssm->getPaginator('DescribeInstanceInformation', []) as $page) {
                    foreach ($page['InstanceInformationList'] ?? [] as $information) {
                        $managedIds[] = $information['InstanceId'];
                    }
                }

                foreach (array_chunk(array_values(array_intersect($instanceIds, $managedIds)), 50) as $chunk) {
                    $patchStates = $ssm->describeInstancePatchStates(['InstanceIds' => $chunk]);
                    foreach ($patchStates['InstancePatchStates'] ?? [] as $state) {
                        Ec2PatchStatus::create([
                            'instance_id' => $state['InstanceId'],
                            'aws_region' => $region,
                            'instance_name' => ($names[$state['InstanceId']] ?? $state['InstanceId']).' ('.$region.')',
                            'missing_count' => $state['MissingCount'] ?? 0,
                            'security_count' => $state['SecurityNonCompliantCount'] ?? 0,
                            'installed_count' => $state['InstalledCount'] ?? 0,
                            'failed_count' => $state['FailedCount'] ?? 0,
                            'reboot_required' => ($state['MissingCount'] ?? 0) > 0 && ($state['RebootOption'] ?? '') !== 'NoReboot',
                            'os_version' => null,
                            'checked_at' => now(),
                        ]);
                        $result['patch_snapshots']++;
                    }
                }
            } catch (\Throwable $exception) {
                $result['failed_regions'][] = $region;
                Log::warning('EC2 patch status collection failed for a region.', ['region' => $region, 'exception' => $exception->getMessage()]);
            }
        }

        return $result;
    }

    private function client(string $service, string $region): object
    {
        if ($this->clientFactory) {
            return ($this->clientFactory)($service, $region);
        }

        return $service === 'ec2'
            ? new Ec2Client(['version' => 'latest', 'region' => $region])
            : new SsmClient(['version' => 'latest', 'region' => $region]);
    }
}