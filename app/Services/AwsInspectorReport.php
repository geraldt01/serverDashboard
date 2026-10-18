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
        foreach ($findings as $finding) {
            $severity = $finding['severity'];
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

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
            'counts' => $counts,
            'total' => count($findings),
            'refreshed_at' => CarbonImmutable::now(),
            'data_note' => $note,
        ];
    }

    private function formatFinding(array $finding, string $region): array
    {
        $resource = $finding['resources'][0] ?? [];
        $vulnerability = $finding['packageVulnerabilityDetails'] ?? [];
        $codeVulnerability = $finding['codeVulnerabilityDetails'] ?? [];
        $packages = $vulnerability['vulnerablePackages'] ?? [];
        $packageNames = array_map(fn ($package) => $package['name'] ?? 'Unknown package', $packages);
        $fixedVersions = array_values(array_filter(array_map(fn ($package) => $package['fixedInVersion'] ?? null, $packages)));
        $codeLocation = trim(($codeVulnerability['detectorName'] ?? '').' '.($codeVulnerability['filePath'] ?? ''));

        return [
            'title' => $finding['Title'] ?? 'Untitled vulnerability finding',
            'type' => $finding['type'] ?? 'Vulnerability',
            'severity' => strtoupper($finding['severity'] ?? 'UNTRIAGED'),
            'resource' => $resource['id'] ?? 'Unknown resource',
            'resource_type' => $resource['type'] ?? 'Unknown type',
            'region' => $resource['region'] ?? $region,
            'vulnerability_id' => $vulnerability['vulnerabilityId'] ?? implode(', ', $codeVulnerability['cwes'] ?? []),
            'packages' => implode(', ', array_slice($packageNames, 0, 3)) ?: ($codeLocation ?: 'Not provided'),
            'fixed_versions' => implode(', ', array_slice($fixedVersions, 0, 3)),
            'exploit_available' => strtoupper($finding['exploitAvailable'] ?? 'NO'),
            'first_observed_at' => $finding['firstObservedAt'] ?? null,
        ];
    }

    private function unavailable(array $regions, string $note): array
    {
        return [
            'available' => false,
            'partial' => false,
            'regions' => [],
            'failed_regions' => $regions,
            'findings' => [],
            'counts' => array_fill_keys(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFORMATIONAL', 'UNTRIAGED'], 0),
            'total' => null,
            'refreshed_at' => CarbonImmutable::now(),
            'data_note' => $note,
        ];
    }
}