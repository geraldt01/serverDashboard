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
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['code' => 'AccessDeniedException']), 'ce:GetCostAndUsage and ce:GetCostForecast'],
            [new AwsException('private-credential-detail', new Command('GetCostAndUsage'), ['code' => 'DataUnavailableException']), 'sufficient forecast history'],
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

    public function test_it_combines_month_to_date_actual_cost_with_the_monthly_forecast(): void
    {
        $today = CarbonImmutable::parse('2026-10-12 10:00:00');
        $client = Mockery::mock(CostExplorerClient::class);
        $client->shouldReceive('getCostAndUsage')
            ->once()
            ->with([
                'TimePeriod' => ['Start' => '2026-10-01', 'End' => '2026-10-12'],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
            ])
            ->andReturn(new Result(['ResultsByTime' => [[
                'Total' => ['UnblendedCost' => ['Amount' => '100.00', 'Unit' => 'USD']],
            ]]]));
        $client->shouldReceive('getCostForecast')
            ->once()
            ->with([
                'TimePeriod' => ['Start' => '2026-10-12', 'End' => '2026-11-01'],
                'Granularity' => 'MONTHLY',
                'Metric' => 'UNBLENDED_COST',
                'PredictionIntervalLevel' => 80,
            ])
            ->andReturn(new Result([
                'Total' => ['Amount' => '250.00', 'Unit' => 'USD'],
                'ForecastResultsByTime' => [[
                    'MeanValue' => '250.00',
                    'PredictionIntervalLowerBound' => '220.00',
                    'PredictionIntervalUpperBound' => '280.00',
                ]],
            ]));

        $costs = (new AwsCostReport($client))->build($today);

        $this->assertTrue($costs['available']);
        $this->assertSame(['Oct 2026'], $costs['labels']);
        $this->assertSame(350.0, $costs['total']);
        $this->assertSame(100.0, $costs['actual_to_date']);
        $this->assertSame(250.0, $costs['forecast_remaining']);
        $this->assertSame(320.0, $costs['lower_bound']);
        $this->assertSame(380.0, $costs['upper_bound']);
        $this->assertSame('USD', $costs['unit']);
        $this->assertSame('Projected monthly cost', $costs['services'][0]['name']);
        $this->assertSame([350.0], $costs['services'][0]['data']);
        $this->assertStringContainsString('not real-time', strtolower($costs['data_note']));
    }
}