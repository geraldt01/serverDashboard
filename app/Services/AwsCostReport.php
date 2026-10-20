<?php

namespace App\Services;

use Aws\CostExplorer\CostExplorerClient;
use Carbon\CarbonImmutable;

class AwsCostReport
{
    public function __construct(private readonly ?CostExplorerClient $client = null)
    {
    }

    public function build(?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::now();
        $monthStart = $today->startOfMonth();
        $todayStart = $today->startOfDay();
        $monthEnd = $monthStart->addMonth();

        if (app()->environment('testing') && $this->client === null) {
            return $this->unavailable($today, 'Cost Explorer is not queried in the test environment.');
        }

        try {
            $client = $this->client ?? new CostExplorerClient([
                'version' => 'latest',
                'region' => 'us-east-1',
                'retries' => 0,
                'http' => ['connect_timeout' => 3, 'timeout' => 8],
            ]);
            $actualAmount = 0.0;
            $unit = 'USD';
            if ($todayStart->greaterThan($monthStart)) {
                $actual = $client->getCostAndUsage([
                    'TimePeriod' => ['Start' => $monthStart->toDateString(), 'End' => $todayStart->toDateString()],
                    'Granularity' => 'MONTHLY',
                    'Metrics' => ['UnblendedCost'],
                ]);
                $actualCost = $actual['ResultsByTime'][0]['Total']['UnblendedCost'] ?? [];
                $actualAmount = (float) ($actualCost['Amount'] ?? 0);
                $unit = $actualCost['Unit'] ?? $unit;
            }

            $forecast = $client->getCostForecast([
                'TimePeriod' => ['Start' => $todayStart->toDateString(), 'End' => $monthEnd->toDateString()],
                'Granularity' => 'MONTHLY',
                'Metric' => 'UNBLENDED_COST',
                'PredictionIntervalLevel' => 80,
            ]);

            return $this->formatForecast($forecast, $actualAmount, $unit, $monthStart, $today);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->unavailable($today, 'Cost Explorer data is unavailable. Check AWS permissions and try again.');
        }
    }

    private function unavailable(CarbonImmutable $today, string $note): array
    {
        return [
            'available' => false,
            'labels' => [],
            'services' => [],
            'total' => null,
            'actual_to_date' => null,
            'forecast_remaining' => null,
            'lower_bound' => null,
            'upper_bound' => null,
            'unit' => null,
            'range_label' => $today->format('F Y').' monthly forecast',
            'refreshed_at' => $today,
            'data_note' => $note,
        ];
    }

    private function formatForecast(array|\Aws\ResultInterface $forecast, float $actualAmount, string $unit, CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $forecastPeriods = $forecast['ForecastResultsByTime'] ?? [];
        $forecastAmount = (float) ($forecast['Total']['Amount'] ?? array_sum(array_map(
            fn ($period) => (float) ($period['MeanValue'] ?? 0),
            $forecastPeriods
        )));
        $lowerBounds = array_column($forecastPeriods, 'PredictionIntervalLowerBound');
        $upperBounds = array_column($forecastPeriods, 'PredictionIntervalUpperBound');
        $lowerBound = $lowerBounds !== [] && ! in_array(null, $lowerBounds, true)
            ? $actualAmount + array_sum(array_map('floatval', $lowerBounds))
            : null;
        $upperBound = $upperBounds !== [] && ! in_array(null, $upperBounds, true)
            ? $actualAmount + array_sum(array_map('floatval', $upperBounds))
            : null;
        $total = $actualAmount + $forecastAmount;
        $label = $monthStart->format('M Y');

        return [
            'available' => true,
            'labels' => [$label],
            'services' => [[
                'name' => 'Projected monthly cost',
                'data' => [round($total, 2)],
                'color' => '#357b91',
            ]],
            'total' => round($total, 2),
            'actual_to_date' => round($actualAmount, 2),
            'forecast_remaining' => round($forecastAmount, 2),
            'lower_bound' => $lowerBound === null ? null : round($lowerBound, 2),
            'upper_bound' => $upperBound === null ? null : round($upperBound, 2),
            'unit' => $unit,
            'range_label' => $today->format('F Y').' monthly forecast',
            'refreshed_at' => $today,
            'data_note' => 'Projected total combines month-to-date actual spend with Cost Explorer’s forecast for the remaining days. AWS billing data is not real-time and may take up to 24 hours or longer to appear.',
        ];
    }
}