<?php

namespace App\Services;

use Aws\Backup\BackupClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
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
        $failures = [];

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
                $failures[] = $region.': '.$this->failureNote($exception);
            }
        }

        if ($queriedRegions === []) {
            return $this->unavailable($regions, 'AWS Backup details could not be retrieved. '.implode(' ', $failures));
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
                : 'Partial AWS Backup results. '.implode(' ', $failures),
        ];
    }

    private function failureNote(\Throwable $exception): string
    {
        if ($exception instanceof CredentialsException) {
            return 'AWS credentials are unavailable to the dashboard process. Configure its IAM role or AWS SDK credential provider.';
        }

        if ($exception instanceof AwsException) {
            return match ($exception->getAwsErrorCode()) {
                'AccessDenied', 'AccessDeniedException', 'UnauthorizedOperation' => 'AWS denied Backup access. Allow backup:ListBackupPlans and backup:ListBackupJobs on resource * for the dashboard IAM identity; check permissions boundaries and organization policies.',
                'ExpiredToken', 'ExpiredTokenException', 'InvalidClientTokenId', 'UnrecognizedClientException', 'InvalidSignatureException', 'SignatureDoesNotMatch' => 'AWS rejected the dashboard credentials. Check the IAM role, credential validity and any session token.',
                'Throttling', 'ThrottlingException', 'LimitExceededException', 'TooManyRequestsException' => 'AWS Backup is rate limited. Try again shortly.',
                'ServiceUnavailableException' => 'AWS Backup is temporarily unavailable. Try again shortly.',
                default => $exception->isConnectionError()
                    ? 'Cannot connect to AWS Backup. Check outbound HTTPS, DNS and TLS configuration for this region.'
                    : 'AWS Backup could not complete the request. Check the server logs for the AWS error code.',
            };
        }

        return 'The dashboard could not read AWS Backup data. Check the server logs for the underlying error.';
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