<?php

namespace Tests\Feature;

use App\Services\AwsCostReport;
use Aws\CostExplorer\CostExplorerClient;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class AwsCostReportTest extends TestCase
{
    public function test_it_queries_monthly_costs_by_region_for_six_months_and_current_month(): void
    {
        $today = CarbonImmutable::parse('2026-10-12 10:00:00');
        $client = Mockery::mock(CostExplorerClient::class);
        $client->shouldReceive('getCostAndUsage')
            ->once()
            ->with([
                'TimePeriod' => ['Start' => '2026-04-01', 'End' => '2026-10-13'],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'REGION']],
            ])
            ->andReturn(['ResultsByTime' => [
                [
                    'TimePeriod' => ['Start' => '2026-04-01', 'End' => '2026-05-01'],
                    'Groups' => [
                        ['Keys' => ['ap-southeast-2'], 'Metrics' => ['UnblendedCost' => ['Amount' => '200.00', 'Unit' => 'USD']]],
                        ['Keys' => ['us-east-2'], 'Metrics' => ['UnblendedCost' => ['Amount' => '20.00', 'Unit' => 'USD']]],
                    ],
                ],
                [
                    'TimePeriod' => ['Start' => '2026-10-01', 'End' => '2026-11-01'],
                    'Groups' => [
                        ['Keys' => ['ap-southeast-2'], 'Metrics' => ['UnblendedCost' => ['Amount' => '100.00', 'Unit' => 'USD']]],
                        ['Keys' => ['us-east-2'], 'Metrics' => ['UnblendedCost' => ['Amount' => '10.00', 'Unit' => 'USD']]],
                    ],
                ],
            ]]);

        $costs = (new AwsCostReport($client))->build($today);

        $this->assertTrue($costs['available']);
        $this->assertSame(['Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'], $costs['labels']);
        $this->assertSame(330.0, $costs['total']);
        $this->assertSame('USD', $costs['unit']);
        $this->assertCount(2, $costs['regions']);
        $australia = collect($costs['regions'])->firstWhere('name', 'ap-southeast-2');
        $this->assertSame([200.0, 0.0, 0.0, 0.0, 0.0, 0.0, 100.0], $australia['data']);
        $this->assertStringContainsString('not real-time', strtolower($costs['data_note']));
    }
}