<?php

namespace Tests\Feature;

use App\Services\AwsCostReport;
use Aws\Command;
use Aws\CostExplorer\CostExplorerClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Result;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class AwsCostReportTest extends TestCase
{
    public function test_failures_have_actionable_messages_without_exposing_raw_errors(): void
    {
        $cases = [
            [new CredentialsException('private-credential-detail'), 'AWS credentials are unavailable'],
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['code' => 'AccessDeniedException']), 'Grant ce:GetCostAndUsage'],
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['code' => 'DataUnavailableException']), 'billing data to become available'],
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['code' => 'ExpiredTokenException']), 'have not expired'],
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['code' => 'LimitExceededException']), 'rate limited'],
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['connection_error' => true]), 'outbound HTTPS'],
            [new \RuntimeException('private-credential-detail'), 'deployed application version'],
        ];

        foreach ($cases as [$exception, $expectedNote]) {
            $client = Mockery::mock(CostExplorerClient::class);
            $client->shouldReceive('getCostAndUsage')->once()->andThrow($exception);
            $client->shouldNotReceive('getCostForecast');

            $costs = (new AwsCostReport($client))->build(CarbonImmutable::parse('2026-10-20'));

            $this->assertFalse($costs['available']);
            $this->assertNull($costs['total']);
            $this->assertStringContainsString($expectedNote, $costs['data_note']);
            $this->assertStringNotContainsString('private-credential-detail', json_encode($costs));
        }
    }

    public function test_it_calculates_a_local_month_end_estimate_from_cost_explorer_data(): void
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
            ->andReturn(new Result(['ResultsByTime' => [[
                'TimePeriod' => ['Start' => '2026-10-01'],
                'Groups' => [[
                    'Keys' => ['Amazon EC2'],
                    'Metrics' => ['UnblendedCost' => ['Amount' => '100.00', 'Unit' => 'USD']],
                ]],
            ]]]));
        $client->shouldNotReceive('getCostForecast');

        $costs = (new AwsCostReport($client))->build($today);

        $this->assertTrue($costs['available']);
        $this->assertSame(['Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'], $costs['labels']);
        $this->assertSame(100.0, $costs['total']);
        $this->assertSame(100.0, $costs['current_month_total']);
        $this->assertSame(258.33, $costs['month_end_estimate']);
        $this->assertSame('USD', $costs['unit']);
        $this->assertSame('Amazon EC2', $costs['services'][0]['name']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 100.0], $costs['services'][0]['data']);
        $this->assertStringContainsString('not real-time', strtolower($costs['data_note']));
    }
}