<?php

namespace Tests\Feature;

use App\Services\AwsCostReport;
use Aws\CostExplorer\CostExplorerClient;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class AwsCostReportTest extends TestCase
{
    public function test_it_queries_monthly_costs_by_service_for_six_months_and_current_month(): void
    {
        $today = CarbonImmutable::parse('2026-10-12 10:00:00');
        $client = Mockery::mock(CostExplorerClient::class);
        $client->shouldReceive('getCostAndUsage')
            ->once()
            ->with([
                'TimePeriod' => ['Start' => '2026-04-01', 'End' => '2026-10-13'],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'SERVICE']],
            ])
            ->andReturn(['ResultsByTime' => [
                [
                    'TimePeriod' => ['Start' => '2026-04-01', 'End' => '2026-05-01'],
                    'Groups' => [
                        ['Keys' => ['EC2 - Compute'], 'Metrics' => ['UnblendedCost' => ['Amount' => '200.00', 'Unit' => 'USD']]],
                        ['Keys' => ['Tax'], 'Metrics' => ['UnblendedCost' => ['Amount' => '20.00', 'Unit' => 'USD']]],
                        ['Keys' => ['EC2 - Other'], 'Metrics' => ['UnblendedCost' => ['Amount' => '10.00', 'Unit' => 'USD']]],
                        ['Keys' => ['Support (Developer)'], 'Metrics' => ['UnblendedCost' => ['Amount' => '5.00', 'Unit' => 'USD']]],
                    ],
                ],
                [
                    'TimePeriod' => ['Start' => '2026-10-01', 'End' => '2026-11-01'],
                    'Groups' => [
                        ['Keys' => ['EC2 - Compute'], 'Metrics' => ['UnblendedCost' => ['Amount' => '100.00', 'Unit' => 'USD']]],
                        ['Keys' => ['Tax'], 'Metrics' => ['UnblendedCost' => ['Amount' => '10.00', 'Unit' => 'USD']]],
                        ['Keys' => ['EC2 - Other'], 'Metrics' => ['UnblendedCost' => ['Amount' => '4.00', 'Unit' => 'USD']]],
                        ['Keys' => ['Support (Developer)'], 'Metrics' => ['UnblendedCost' => ['Amount' => '2.00', 'Unit' => 'USD']]],
                    ],
                ],
            ]]);

        $costs = (new AwsCostReport($client))->build($today);

        $this->assertTrue($costs['available']);
        $this->assertSame(['Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'], $costs['labels']);
        $this->assertSame(351.0, $costs['total']);
        $this->assertSame('USD', $costs['unit']);
        $this->assertCount(4, $costs['services']);
        $compute = collect($costs['services'])->firstWhere('name', 'EC2 - Compute');
        $this->assertSame([200.0, 0.0, 0.0, 0.0, 0.0, 0.0, 100.0], $compute['data']);
        $this->assertNotNull(collect($costs['services'])->firstWhere('name', 'Tax'));
        $this->assertNotNull(collect($costs['services'])->firstWhere('name', 'EC2 - Other'));
        $this->assertNotNull(collect($costs['services'])->firstWhere('name', 'Support (Developer)'));
        $this->assertStringContainsString('not real-time', strtolower($costs['data_note']));
    }
}