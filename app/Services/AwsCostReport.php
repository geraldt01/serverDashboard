<?php

namespace App\Services;

use Aws\CostExplorer\CostExplorerClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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
            $note = $this->failureNote($exception);
            Log::warning('AWS cost history unavailable.', ['reason' => $note]);

            return $this->unavailable($today, $note);
        }
    }

    private function failureNote(\Throwable $exception): string
    {
        if ($exception instanceof CredentialsException) {
            return 'AWS credentials are unavailable to the dashboard process. Configure its IAM role or AWS SDK credential provider, then refresh costs.';
        }

        if ($exception instanceof AwsException) {
            return match ($exception->getAwsErrorCode()) {
                'AccessDenied', 'AccessDeniedException', 'UnauthorizedOperation' => 'AWS denied Cost Explorer access. Grant ce:GetCostAndUsage to the dashboard identity; check organization policies and billing-account restrictions.',
                'DataUnavailableException' => 'AWS has no cost data available yet. Enable Cost Explorer in the billing account and allow time for billing data to become available.',
                'OptInRequired', 'OptInRequiredException' => 'Cost Explorer is not enabled for this AWS account. Enable it in AWS Billing and Cost Management, then allow time for data preparation.',
                'InvalidClientTokenId', 'UnrecognizedClientException', 'ExpiredToken', 'ExpiredTokenException', 'InvalidSignatureException', 'SignatureDoesNotMatch' => 'AWS rejected the dashboard credentials. Check that its credentials and any session token are valid and have not expired.',
                'LimitExceededException', 'Throttling', 'ThrottlingException', 'TooManyRequestsException' => 'AWS Cost Explorer is temporarily rate limited. Wait briefly before refreshing costs.',
                default => $exception->isConnectionError()
                    ? 'The dashboard could not connect to AWS Cost Explorer. Check outbound HTTPS access to ce.us-east-1.amazonaws.com and the server TLS configuration.'
                    : 'AWS Cost Explorer could not complete the request. Ask the dashboard administrator to check the AWS billing configuration and server diagnostics.',
            };
        }

        return 'The dashboard could not build the AWS cost history. Ask the administrator to verify the deployed application version and server configuration.';
    }

    private function unavailable(CarbonImmutable $today, string $note): array
    {
        return [
            'available' => false,
            'labels' => [],
            'services' => [],
            'total' => null,
            'current_month_total' => null,
            'month_end_estimate' => null,
            'unit' => null,
            'range_label' => 'Last 6 months + current month to date',
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
        $currentMonthIndex = array_search($today->format('M Y'), $labels, true);
        $currentMonthTotal = $currentMonthIndex === false
            ? null
            : array_sum(array_map(fn ($service) => $service['data'][$currentMonthIndex] ?? 0, $services));
        $monthEndEstimate = $currentMonthTotal === null
            ? null
            : round($currentMonthTotal / $today->day * $today->daysInMonth, 2);

        return [
            'available' => true,
            'labels' => $labels,
            'services' => $services,
            'total' => round($total, 2),
            'current_month_total' => $currentMonthTotal,
            'month_end_estimate' => $monthEndEstimate,
            'unit' => $unit,
            'range_label' => 'Last 6 months + current month to date',
            'refreshed_at' => $today,
            'data_note' => 'Fetched live from AWS Cost Explorer for this page view. AWS billing data is not real-time and may take up to 24 hours or longer to appear.',
        ];
    }
}