<?php

namespace App\Services;

use Aws\Backup\BackupClient;
use Carbon\CarbonImmutable;

class AwsBackupReport
{
    public function __construct(private readonly ?BackupClient $client = null)
    {
    }

    public function build(): array
    {
        $regions = config('services.backup.regions', [config('services.aws.region')]);
        $regions = array_values(array_unique(array_filter(array_map('trim', $regions))));
        $regions = $regions ?: [config('services.aws.region', 'us-east-1')];
        $plans = [];
        $jobs = [];
        $queriedRegions = [];
        $failedRegions = [];

        if (app()->environment('testing') && $this->client === null) {
            return $this->unavailable($regions, 'AWS Backup is not queried in the test environment.');
        }

        foreach ($regions as $region) {
            try {
                $client = $this->client ?? new BackupClient([
                    'version' => 'latest',
                    'region' => $region,
                    'retries' => 0,
                    'http' => ['connect_timeout' => 3, 'timeout' => 8],
                ]);

                foreach ($this->listAll($client, 'listBackupPlans', 'BackupPlansList') as $plan) {
                    $plans[] = [
                        'name' => $plan['BackupPlanName'] ?? 'Unnamed backup plan',
                        'region' => $region,
                    ];
                }

                foreach ($this->listAll($client, 'listBackupJobs', 'BackupJobs') as $job) {
                    $jobs[] = [
                        'state' => $job['State'] ?? 'UNKNOWN',
                        'resource' => $job['ResourceName'] ?? $job['ResourceType'] ?? 'Unknown resource',
                        'region' => $region,
                        'created_at' => $this->date($job['CreationDate'] ?? null),
                    ];
                }

                $queriedRegions[] = $region;
            } catch (\Throwable $exception) {
                report($exception);
                $failedRegions[] = $region;
            }
        }

        if ($queriedRegions === []) {
            return $this->unavailable($regions, 'AWS Backup details could not be retrieved. Check AWS credentials and backup:ListBackupPlans and backup:ListBackupJobs permissions.');
        }

        usort($jobs, fn ($left, $right) => ($right['created_at']?->getTimestamp() ?? 0) <=> ($left['created_at']?->getTimestamp() ?? 0));
        $jobCounts = [];
        foreach ($jobs as $job) {
            $jobCounts[$job['state']] = ($jobCounts[$job['state']] ?? 0) + 1;
        }

        return [
            'available' => true,
            'partial' => $failedRegions !== [],
            'regions' => $queriedRegions,
            'failed_regions' => $failedRegions,
            'plans' => $plans,
            'jobs' => array_slice($jobs, 0, 20),
            'job_counts' => $jobCounts,
            'refreshed_at' => CarbonImmutable::now(),
            'data_note' => $failedRegions === []
                ? 'Read-only AWS Backup plans and backup jobs from the last 30 days. Native backups outside AWS Backup are not included.'
                : 'Partial AWS Backup results; data could not be retrieved in '.implode(', ', $failedRegions).'.',
        ];
    }

    private function listAll(BackupClient $client, string $method, string $resultKey): array
    {
        $items = [];
        $nextToken = null;

        do {
            $params = ['MaxResults' => 1000];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $result = $client->{$method}($params);
            array_push($items, ...($result[$resultKey] ?? []));
            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken);

        return $items;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value) : null;
    }

    private function unavailable(array $regions, string $note): array
    {
        return [
            'available' => false,
            'partial' => false,
            'regions' => [],
            'failed_regions' => $regions,
            'plans' => [],
            'jobs' => [],
            'job_counts' => [],
            'refreshed_at' => CarbonImmutable::now(),
            'data_note' => $note,
        ];
    }
}