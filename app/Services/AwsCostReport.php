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
        $start = $today->startOfMonth()->subMonths(6);
        $end = $today->startOfDay()->addDay();

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
            $result = $client->getCostAndUsage([
                'TimePeriod' => ['Start' => $start->toDateString(), 'End' => $end->toDateString()],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'SERVICE']],
            ]);

            return $this->formatResults($result['ResultsByTime'] ?? [], $start, $end, $today);
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
            'unit' => null,
            'range_label' => 'Last 6 months',
            'refreshed_at' => $today,
            'data_note' => $note,
        ];
    }

    private function formatResults(array $periods, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $today): array
    {
        $labels = [];
        for ($month = $start; $month < $end; $month = $month->addMonth()) {
            $labels[] = $month->format('M Y');
        }

        $serviceValues = [];
        $unit = 'USD';
        foreach ($periods as $period) {
            $month = CarbonImmutable::parse($period['TimePeriod']['Start'])->format('M Y');
            foreach ($period['Groups'] ?? [] as $group) {
                $service = $group['Keys'][0] ?? 'Other / no service';
                $amount = (float) ($group['Metrics']['UnblendedCost']['Amount'] ?? 0);
                $unit = $group['Metrics']['UnblendedCost']['Unit'] ?? $unit;
                $serviceValues[$service][$month] = ($serviceValues[$service][$month] ?? 0) + $amount;
            }
        }

        $palette = ['#5279df', '#c81e78', '#e2173d', '#168a72', '#a16bcc', '#b06a00', '#357b91', '#5f6c7b'];
        $services = [];
        $total = 0.0;
        foreach ($serviceValues as $service => $values) {
            $data = array_map(fn ($label) => round($values[$label] ?? 0, 2), $labels);
            $total += array_sum($data);
            $services[] = [
                'name' => $service === '' ? 'Other / no service' : $service,
                'data' => $data,
                'color' => $palette[count($services) % count($palette)],
            ];
        }
        usort($services, fn ($left, $right) => array_sum($right['data']) <=> array_sum($left['data']));

        return [
            'available' => true,
            'labels' => $labels,
            'services' => $services,
            'total' => round($total, 2),
            'unit' => $unit,
            'range_label' => 'Last 6 months + current month to date',
            'refreshed_at' => $today,
            'data_note' => 'Fetched live from AWS Cost Explorer for this page view. AWS billing data is not real-time and may take up to 24 hours or longer to appear.',
        ];
    }
}