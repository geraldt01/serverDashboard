<?php

namespace App\Services;

use App\Models\UptimeCheck;
use App\Models\WordpressSite;
use Aws\Ec2\Ec2Client;
use Illuminate\Support\Facades\Log;

class UptimeMonitor
{
    public function __construct(private readonly WebpageHealthChecker $webpageChecker, private readonly ?Ec2Client $ec2Client = null)
    {
    }

    public function run(): array
    {
        $checked = 0;
        $failed = 0;
        $at = now();

        WordpressSite::query()->where('is_active', true)->orderBy('id')->chunk(100, function ($sites) use (&$checked, &$failed, $at) {
            foreach ($sites as $site) {
                $result = $this->webpageChecker->checkAvailability($site->url);
                UptimeCheck::create([
                    'asset_type' => 'wordpress',
                    'asset_key' => (string) $site->id,
                    'asset_name' => $site->name,
                    'is_up' => $result['is_up'],
                    'http_status' => $result['http_status'],
                    'response_time_ms' => $result['response_time_ms'],
                    'detail' => $result['detail'],
                    'checked_at' => $at,
                ]);
                $checked++;
                $failed += $result['is_up'] ? 0 : 1;
            }
        });

        try {
            $regions = $this->ec2Client ? [config('services.aws.region')] : $this->enabledRegions();
            foreach ($regions as $region) {
                try {
                    $ec2 = $this->ec2Client ?? new Ec2Client(['version' => 'latest', 'region' => $region]);
                    foreach ($ec2->getPaginator('DescribeInstanceStatus', ['IncludeAllInstances' => true]) as $page) {
                        foreach ($page['InstanceStatuses'] as $status) {
                            $instanceState = $status['InstanceState']['Name'] ?? 'unknown';
                            $instanceStatus = $status['InstanceStatus']['Status'] ?? 'unknown';
                            $systemStatus = $status['SystemStatus']['Status'] ?? 'unknown';
                            $isUp = $instanceState === 'running' && $instanceStatus === 'ok' && $systemStatus === 'ok';
                            $instanceId = $status['InstanceId'];
                            UptimeCheck::create([
                                'asset_type' => 'ec2',
                                'asset_key' => $region.':'.$instanceId,
                                'asset_name' => $instanceId.' ('.$region.')',
                                'is_up' => $isUp,
                                'detail' => "Region: {$region}; instance: {$instanceState}; instance check: {$instanceStatus}; system check: {$systemStatus}",
                                'checked_at' => $at,
                            ]);
                            $checked++;
                            $failed += $isUp ? 0 : 1;
                        }
                    }
                } catch (\Throwable $exception) {
                    $failed++;
                    Log::warning('EC2 uptime polling failed for a region.', ['region' => $region, 'exception' => $exception->getMessage()]);
                }
            }
        } catch (\Throwable $exception) {
            $failed++;
            Log::warning('EC2 uptime polling failed.', ['exception' => $exception->getMessage()]);
        }

        UptimeCheck::query()->where('checked_at', '<', now()->subYear())->delete();

        return ['checked' => $checked, 'failed' => $failed];
    }

    private function enabledRegions(): array
    {
        $client = new Ec2Client(['version' => 'latest', 'region' => config('services.aws.region')]);
        $regions = $client->describeRegions(['AllRegions' => false])['Regions'] ?? [];

        return collect($regions)->pluck('RegionName')->filter()->values()->all();
    }
}