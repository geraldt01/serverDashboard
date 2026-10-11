<?php

namespace Tests\Feature;

use App\Services\Ec2PatchStatusCollector;
use Aws\Ec2\Ec2Client;
use Aws\Ssm\SsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Ec2PatchStatusCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_persists_region_aware_ssm_patch_status_without_installing_updates(): void
    {
        config(['services.aws.region' => 'ap-southeast-2']);
        $ec2 = Mockery::mock(Ec2Client::class);
        $ec2->shouldReceive('describeRegions')->once()->with(['AllRegions' => false])->andReturn([
            'Regions' => [['RegionName' => 'ap-southeast-2']],
        ]);
        $ec2->shouldReceive('getPaginator')->once()->with('DescribeInstances', [])->andReturn([
            [
                'Reservations' => [
                    [
                        'Instances' => [[
                            'InstanceId' => 'i-abcd1234',
                            'Tags' => [['Key' => 'Name', 'Value' => 'Production App']],
                        ]],
                    ],
                ],
            ],
        ]);
        $ssm = Mockery::mock(SsmClient::class);
        $ssm->shouldReceive('getPaginator')->once()->with('DescribeInstanceInformation', [])->andReturn([
            ['InstanceInformationList' => [['InstanceId' => 'i-abcd1234']]],
        ]);
        $ssm->shouldReceive('describeInstancePatchStates')->once()->with(['InstanceIds' => ['i-abcd1234']])->andReturn([
            'InstancePatchStates' => [[
                'InstanceId' => 'i-abcd1234',
                'MissingCount' => 4,
                'SecurityNonCompliantCount' => 2,
                'InstalledCount' => 100,
                'FailedCount' => 0,
                'RebootOption' => 'RebootIfNeeded',
            ]],
        ]);
        $collector = new Ec2PatchStatusCollector(fn ($service) => $service === 'ec2' ? $ec2 : $ssm);

        $result = $collector->collect();

        $this->assertSame(['regions' => 1, 'instances' => 1, 'patch_snapshots' => 1, 'failed_regions' => []], $result);
        $this->assertDatabaseHas('ec2_patch_statuses', [
            'instance_id' => 'i-abcd1234',
            'aws_region' => 'ap-southeast-2',
            'instance_name' => 'Production App (ap-southeast-2)',
            'missing_count' => 4,
            'security_count' => 2,
            'reboot_required' => true,
        ]);
    }
}