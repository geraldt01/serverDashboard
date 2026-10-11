<?php

namespace Tests\Feature;

use App\Models\UptimeCheck;
use App\Models\WordpressSite;
use App\Services\UptimeMonitor;
use App\Services\WebpageHealthChecker;
use Aws\Ec2\Ec2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UptimeMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_persists_wordpress_and_ec2_availability_results(): void
    {
        WordpressSite::forceCreate([
            'name' => 'Example Site',
            'slug' => 'example-site',
            'url' => 'https://example.com',
            'monitor_token' => hash('sha256', 'test-token'),
            'is_active' => true,
        ]);
        $checker = Mockery::mock(WebpageHealthChecker::class);
        $checker->shouldReceive('checkAvailability')->once()->with('https://example.com')->andReturn([
            'is_up' => false,
            'http_status' => 503,
            'response_time_ms' => 240,
            'detail' => 'HTTP 503',
        ]);
        $ec2 = Mockery::mock(Ec2Client::class);
        $ec2->shouldReceive('getPaginator')->once()->with('DescribeInstanceStatus', ['IncludeAllInstances' => true])->andReturn([[
            'InstanceStatuses' => [[
                'InstanceId' => 'i-123',
                'InstanceState' => ['Name' => 'running'],
                'InstanceStatus' => ['Status' => 'ok'],
                'SystemStatus' => ['Status' => 'ok'],
            ]],
        ]]);

        $result = (new UptimeMonitor($checker, $ec2))->run();

        $this->assertSame(['checked' => 2, 'failed' => 1], $result);
        $this->assertDatabaseCount('uptime_checks', 2);
        $this->assertDatabaseHas('uptime_checks', ['asset_type' => 'wordpress', 'asset_name' => 'Example Site', 'is_up' => false, 'http_status' => 503]);
        $this->assertDatabaseHas('uptime_checks', ['asset_type' => 'ec2', 'asset_key' => config('services.aws.region').':i-123', 'is_up' => true]);
    }
}