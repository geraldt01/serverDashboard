<?php

namespace App\Services;

use Aws\Inspector2\Inspector2Client;
use Carbon\CarbonImmutable;

class AwsInspectorReport
{
    public function __construct(private readonly ?Inspector2Client $client = null)
    {
    }

    public function build(): array
    {
        $regions = config('services.inspector.regions', [config('services.aws.region')]);
        $regions = array_values(array_unique(array_filter(array_map('trim', $regions))));
        $regions = $regions ?: [config('services.aws.region', 'us-east-1')];
        $findings = [];
        $failedRegions = [];
        $queriedRegions = [];

        if (app()->environment('testing') && $this->client === null) {
            return $this->unavailable($regions, 'Inspector is not queried in the test environment.');
        }

        foreach ($regions as $region) {
            try {
                $client = $this->client ?? new Inspector2Client([
                    'version' => 'latest',
                    'region' => $region,
                    'retries' => 0,
                    'http' => ['connect_timeout' => 3, 'timeout' => 8],
                ]);
                $nextToken = null;

                do {
                    $params = [
                        'filterCriteria' => [
                            'findingStatus' => [['comparison' => 'EQUALS', 'value' => 'ACTIVE']],
                            'findingType' => [
                                ['comparison' => 'EQUALS', 'value' => 'PACKAGE_VULNERABILITY'],
                                ['comparison' => 'EQUALS', 'value' => 'CODE_VULNERABILITY'],
                            ],
                        ],
                        'maxResults' => 100,
                    ];
                    if ($nextToken) {
                        $params['nextToken'] = $nextToken;
                    }

                    $result = $client->listFindings($params);
                    foreach ($result['findings'] ?? [] as $finding) {
                        $findings[] = $this->formatFinding($finding, $region);
                    }
                    $nextToken = $result['nextToken'] ?? null;
                } while ($nextToken);

                $queriedRegions[] = $region;
            } catch (\Throwable $exception) {
                report($exception);
                $failedRegions[] = $region;
            }
        }

        $counts = array_fill_keys(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFORMATIONAL', 'UNTRIAGED'], 0);
        $packageCoverage = [
            'total' => 0,
            'exploit' => ['YES' => 0, 'NO' => 0, 'PARTIAL' => 0, 'NOT_REPORTED' => 0],
            'fix' => ['YES' => 0, 'NO' => 0, 'PARTIAL' => 0, 'NOT_REPORTED' => 0],
        ];
        foreach ($findings as $finding) {
            $severity = $finding['severity'];
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;

            if ($finding['type'] !== 'PACKAGE_VULNERABILITY') {
                continue;
            }

            $packageCoverage['total']++;
            $packageCoverage['exploit'][$finding['exploit_available']]++;
            $packageCoverage['fix'][$finding['fix_available']]++;
        }
        $fixAvailableMachines = $this->fixAvailableMachines($findings);

        if ($queriedRegions === []) {
            return $this->unavailable($regions, 'Inspector findings could not be retrieved. Check AWS credentials, Inspector enablement, and inspector2:ListFindings permissions.');
        }

        $partial = $failedRegions !== [];
        $note = $partial
            ? 'Partial results: Inspector findings could not be retrieved in '.implode(', ', $failedRegions).'. Check Inspector enablement and inspector2:ListFindings permissions in those regions.'
            : 'Live snapshot of active Amazon Inspector package vulnerability findings. Findings are regional and reflect Inspector coverage at report-view time.';

        return [
            'available' => true,
            'partial' => $partial,
            'regions' => $queriedRegions,
            'failed_regions' => $failedRegions,
            'findings' => $findings,
            'fix_available_machines' => $fixAvailableMachines,
            'counts' => $counts,
            'package_coverage' => $packageCoverage,
            'total' => count($findings),
            'refreshed_at' => CarbonImmutable::now(),
            'data_note' => $note,
        ];
    }

    private function formatFinding(array $finding, string $region): array
    {
        $resources = array_map(function ($resource) use ($finding, $region) {
            $instance = $resource['details']['awsEc2Instance'] ?? [];
            $tags = $resource['tags'] ?? [];
            $resourceRegion = $resource['region'] ?? $region;
            $resourceAccount = $resource['providerAccountId'] ?? $finding['awsAccountId'] ?? 'Unknown';
            $resourceId = $resource['id'] ?? 'Unknown resource';

            return [
                'id' => $resourceId,
                'type' => $resource['type'] ?? 'Unknown type',
                'name' => $tags['Name'] ?? $resourceId,
                'operating_system' => $instance['platform'] ?? 'Unknown',
                'image_id' => $instance['imageId'] ?? 'Unknown',
                'account' => $resourceAccount,
                'cloud_provider' => strtoupper($resource['provider'] ?? 'AWS'),
                'region' => $resourceRegion,
                'key' => implode('|', [$resourceRegion, $resourceAccount, $resourceId]),
            ];
        }, $finding['resources'] ?? []);
        $resource = $finding['resources'][0] ?? [];
        $vulnerability = $finding['packageVulnerabilityDetails'] ?? [];
        $codeVulnerability = $finding['codeVulnerabilityDetails'] ?? [];
        $packages = $vulnerability['vulnerablePackages'] ?? [];
        $packageNames = array_map(fn ($package) => $package['name'] ?? 'Unknown package', $packages);
        $fixedVersions = array_values(array_filter(array_map(fn ($package) => $package['fixedInVersion'] ?? null, $packages)));
        $codeLocation = trim(($codeVulnerability['detectorName'] ?? '').' '.($codeVulnerability['filePath'] ?? ''));
        $firstObservedAt = isset($finding['firstObservedAt'])
            ? CarbonImmutable::parse($finding['firstObservedAt'])->timezone(config('app.timezone'))
            : null;
        $ageDays = $firstObservedAt
            ? (int) $firstObservedAt->startOfDay()->diffInDays(CarbonImmutable::now(config('app.timezone'))->startOfDay())
            : null;

        return [
            'title' => $finding['title'] ?? 'Untitled vulnerability finding',
            'type' => strtoupper($finding['type'] ?? 'VULNERABILITY'),
            'severity' => strtoupper($finding['severity'] ?? 'UNTRIAGED'),
            'resource' => $resource['id'] ?? 'Unknown resource',
            'resource_type' => $resource['type'] ?? 'Unknown type',
            'region' => $resource['region'] ?? $region,
            'vulnerability_id' => $vulnerability['vulnerabilityId'] ?? implode(', ', $codeVulnerability['cwes'] ?? []),
            'packages' => implode(', ', array_slice($packageNames, 0, 3)) ?: ($codeLocation ?: 'Not provided'),
            'fixed_versions' => implode(', ', array_slice($fixedVersions, 0, 3)),
            'exploit_available' => $this->availabilityStatus($finding['exploitAvailable'] ?? null),
            'fix_available' => $this->availabilityStatus($finding['fixAvailable'] ?? null),
            'first_observed_at' => $firstObservedAt,
            'age_days' => $ageDays,
            'finding_arn' => $finding['findingArn'] ?? null,
            'resources' => $resources,
            'machine_keys' => array_values(array_unique(array_column(array_filter($resources, fn ($item) => $item['type'] === 'AWS_EC2_INSTANCE'), 'key'))),
        ];
    }

    private function fixAvailableMachines(array $findings): array
    {
        $machines = [];

        foreach ($findings as $finding) {
            if ($finding['type'] !== 'PACKAGE_VULNERABILITY' || $finding['fix_available'] !== 'YES') {
                continue;
            }

            $seenOnFinding = [];
            foreach ($finding['resources'] as $resource) {
                if ($resource['type'] !== 'AWS_EC2_INSTANCE') {
                    continue;
                }

                $key = $resource['key'];
                if (isset($seenOnFinding[$key])) {
                    continue;
                }
                $seenOnFinding[$key] = true;

                if (! isset($machines[$key])) {
                    $machines[$key] = $resource + [
                        'key' => $key,
                        'critical' => 0,
                        'high' => 0,
                        'all' => 0,
                    ];
                }

                $machines[$key]['all']++;
                if ($finding['severity'] === 'CRITICAL') {
                    $machines[$key]['critical']++;
                } elseif ($finding['severity'] === 'HIGH') {
                    $machines[$key]['high']++;
                }
            }
        }

        usort($machines, fn ($left, $right) => $right['all'] <=> $left['all']);

        return array_values($machines);
    }

    private function availabilityStatus(?string $status): string
    {
        $status = strtoupper($status ?? 'NOT_REPORTED');

        return in_array($status, ['YES', 'NO', 'PARTIAL'], true) ? $status : 'NOT_REPORTED';
    }

    private function unavailable(array $regions, string $note): array
    {
        return [
            'available' => false,
            'partial' => false,
            'regions' => [],
            'failed_regions' => $regions,
            'findings' => [],
            'fix_available_machines' => [],
            'counts' => array_fill_keys(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFORMATIONAL', 'UNTRIAGED'], 0),
            'package_coverage' => [
                'total' => null,
                'exploit' => ['YES' => 0, 'NO' => 0, 'PARTIAL' => 0, 'NOT_REPORTED' => 0],
                'fix' => ['YES' => 0, 'NO' => 0, 'PARTIAL' => 0, 'NOT_REPORTED' => 0],
            ],
            'total' => null,
            'refreshed_at' => CarbonImmutable::now(),
            'data_note' => $note,
        ];
    }
}