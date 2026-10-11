<?php

namespace App\Services;

use App\Models\Ec2PatchStatus;
use App\Models\MonthlyReportSetting;
use App\Models\OtherServer;
use App\Models\SupportTicketChange;
use App\Models\User;
use App\Models\UptimeCheck;
use App\Models\WebpageCheck;
use App\Models\WordpressCoreUpdate;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use Aws\CostExplorer\CostExplorerClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class MonthlyItSecurityReport
{
    public function period(?CarbonImmutable $month = null): array
    {
        $end = ($month ?? CarbonImmutable::now())->startOfMonth();
        $start = $end->subMonth();

        return ['start' => $start, 'end' => $end, 'label' => $start->format('F Y')];
    }

    public function build(?CarbonImmutable $month = null): array
    {
        $period = $this->period($month);
        $latestEc2 = Ec2PatchStatus::query()
            ->whereIn('id', Ec2PatchStatus::query()->selectRaw('MAX(id)')->groupBy('aws_region', 'instance_id'))
            ->orderBy('instance_name')->get();
        $servers = OtherServer::query()->where('is_active', true)->orderBy('name')->get();
        $sites = WordpressSite::query()->orderBy('name')->get();
        $pluginRows = WordpressPluginUpdate::query()->whereBetween('checked_at', [$period['start'], $period['end']->subSecond()])->get();
        $coreRows = WordpressCoreUpdate::query()->whereBetween('checked_at', [$period['start'], $period['end']->subSecond()])->get();
        $ec2MonthRows = Ec2PatchStatus::query()->whereBetween('checked_at', [$period['start'], $period['end']->subSecond()])->orderBy('checked_at')->get();
        $webpageChecks = WebpageCheck::query()->where('is_active', true)->orderBy('name')->get();
        $users = User::query()->orderBy('name')->get(['name', 'email', 'role', 'two_factor_confirmed_at']);
        $ticketChanges = SupportTicketChange::query()->with(['ticket', 'actor'])
            ->whereBetween('created_at', [$period['start'], $period['end']->subSecond()])
            ->latest()->get();
        $outdatedPlugins = $pluginRows->where('status', 'outdated')->unique(fn ($row) => $row->wordpress_site_id.':'.$row->plugin_name)->values();
        $outdatedCore = $coreRows->where('status', 'outdated')->unique('wordpress_site_id')->values();
        $latestEc2 = $latestEc2->keyBy(fn ($status) => ($status->aws_region ?: config('services.aws.region')).':'.$status->instance_id);
        $ec2Uptime = UptimeCheck::query()->where('asset_type', 'ec2')
            ->whereIn('id', UptimeCheck::query()->selectRaw('MAX(id)')->where('asset_type', 'ec2')->groupBy('asset_key'))
            ->orderBy('asset_name')->get();

        $awsTrackedIds = $latestEc2->pluck('instance_id')->all();
        $serverInventory = $servers->filter(fn ($server) => ! $server->aws_instance_id || ! in_array($server->aws_instance_id, $awsTrackedIds, true));
        $securityPatchesPending = (int) $latestEc2->sum('security_count') + (int) $serverInventory->sum('security_updates');
        $patchesPending = (int) $latestEc2->sum('missing_count') + (int) $serverInventory->sum('total_updates');
        $siteUpdateFindings = $outdatedPlugins->count() + $outdatedCore->count();
        $uptime = $this->uptimeSummary($period);
        $costs = $this->awsCosts($period);

        return [
            'period' => $period,
            'ec2' => ['latest' => $latestEc2, 'count' => $ec2Uptime->count(), 'patch_instances' => $latestEc2->count(), 'patches_pending' => $patchesPending, 'security_pending' => $securityPatchesPending],
            'ec2_inventory' => $ec2Uptime,
            'servers' => $serverInventory,
            'sites' => $sites,
            'webpage_checks' => $webpageChecks,
            'ticket_changes' => $ticketChanges,
            'vulnerabilities' => [
                'plugin_findings' => $outdatedPlugins,
                'core_findings' => $outdatedCore,
                'resolved_update_findings' => $this->countResolvedFindings($pluginRows, fn ($row) => $row->wordpress_site_id.':'.$row->plugin_name)
                    + $this->countResolvedFindings($coreRows, fn ($row) => (string) $row->wordpress_site_id),
                'patches_fixed_estimate' => $this->estimatePatchReduction($ec2MonthRows),
            ],
            'uptime' => $uptime,
            'users' => $users,
            'costs' => $costs,
            'backups' => [
                ['asset' => 'EC2 instances', 'policy' => 'Weekly AWS backups (reported policy; not verified by this dashboard)', 'restore_test' => 'Quarterly restore test; latest completion not recorded'],
                ['asset' => 'WordPress websites', 'policy' => 'Daily WP Engine backups (reported policy; not verified by this dashboard)', 'restore_test' => 'Quarterly restore test; latest completion not recorded'],
            ],
            'improvement' => $this->improvement($securityPatchesPending, $patchesPending, $siteUpdateFindings, $uptime),
            'risks' => $this->risks($latestEc2, $serverInventory, $sites, $webpageChecks, $users, $uptime, $costs),
            'security_activity' => [
                'security_patch_records' => Ec2PatchStatus::query()->whereBetween('checked_at', [$period['start'], $period['end']->subSecond()])->count(),
                'plugin_updates_checked' => $pluginRows->count(),
                'core_updates_checked' => $coreRows->count(),
                'ticket_changes' => $ticketChanges->count(),
            ],
        ];
    }

    private function uptimeSummary(array $period): array
    {
        $checks = UptimeCheck::query()
            ->whereBetween('checked_at', [$period['start'], $period['end']->subSecond()])
            ->get();
        $total = $checks->count();
        $assets = $checks->groupBy(fn ($check) => $check->asset_type.':'.$check->asset_key)->map(function ($rows) {
            $up = $rows->where('is_up', true)->count();

            return [
                'type' => $rows->first()->asset_type,
                'name' => $rows->first()->asset_name,
                'checks' => $rows->count(),
                'percent' => round($up * 100 / $rows->count(), 3),
            ];
        })->values();

        return [
            'percent' => $total > 0 ? round($checks->where('is_up', true)->count() * 100 / $total, 3) : null,
            'checks' => $checks,
            'assets' => $assets,
            'target' => 99.9,
        ];
    }

    private function awsCosts(array $period): array
    {
        if (app()->environment('testing')) {
            return ['available' => false, 'groups' => collect(), 'total' => null, 'unit' => null];
        }

        return Cache::remember('monthly-it-report-costs-'.$period['start']->toDateString(), now()->addHours(6), function () use ($period) {
            try {
                $client = new CostExplorerClient(['version' => 'latest', 'region' => 'us-east-1', 'retries' => 0, 'http' => ['connect_timeout' => 3, 'timeout' => 8]]);
                $result = $client->getCostAndUsage([
                    'TimePeriod' => ['Start' => $period['start']->toDateString(), 'End' => $period['end']->toDateString()],
                    'Granularity' => 'MONTHLY',
                    'Metrics' => ['UnblendedCost'],
                    'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'SERVICE']],
                ]);
                $groups = collect($result['ResultsByTime'][0]['Groups'] ?? [])->map(fn ($group) => [
                    'service' => $group['Keys'][0] ?? 'Other',
                    'amount' => (float) ($group['Metrics']['UnblendedCost']['Amount'] ?? 0),
                    'unit' => $group['Metrics']['UnblendedCost']['Unit'] ?? 'USD',
                ])->sortByDesc('amount')->values();

                return ['available' => true, 'groups' => $groups, 'total' => (float) ($result['ResultsByTime'][0]['Total']['UnblendedCost']['Amount'] ?? 0), 'unit' => $result['ResultsByTime'][0]['Total']['UnblendedCost']['Unit'] ?? 'USD'];
            } catch (\Throwable $exception) {
                report($exception);

                return ['available' => false, 'groups' => collect(), 'total' => null, 'unit' => null];
            }
        });
    }

    private function improvement(int $securityPending, int $patchesPending, int $siteFindings, array $uptime): string
    {
        if ($securityPending > 0) {
            return "Review and remediate {$securityPending} outstanding EC2/agent security update(s), then verify patch compliance.";
        }
        if ($siteFindings > 0) {
            return "Review {$siteFindings} outdated WordPress plugin/core finding(s) and confirm updates in staging before production rollout.";
        }
        if ($uptime['percent'] !== null && $uptime['percent'] < $uptime['target']) {
            return 'Investigate the uptime failures and add an alert/runbook for the affected services.';
        }

        return $patchesPending > 0
            ? "Review the {$patchesPending} pending server update(s) and schedule a controlled maintenance window."
            : 'Complete an AWS Cost Explorer review and identify one rightsizing, schedule, storage-lifecycle, or commitment-saving opportunity.';
    }

    private function countResolvedFindings($rows, callable $key): int
    {
        return $rows->groupBy($key)->filter(function ($history) {
            $history = $history->sortBy('checked_at');

            return $history->contains('status', 'outdated') && $history->last()->status !== 'outdated';
        })->count();
    }

    private function estimatePatchReduction($rows): int
    {
        return (int) $rows->groupBy(fn ($row) => ($row->aws_region ?: config('services.aws.region')).':'.$row->instance_id)->sum(function ($history) {
            $history = $history->sortBy('checked_at');

            return max(0, (int) $history->first()->missing_count - (int) $history->last()->missing_count);
        });
    }

    private function risks($ec2, $servers, $sites, $webpageChecks, $users, array $uptime, array $costs): array
    {
        $risks = [];
        if ($ec2->sum('security_count') + $servers->sum('security_updates') > 0) {
            $risks[] = 'One or more servers report outstanding security updates.';
        }
        if ($sites->contains(fn ($site) => $site->last_core_status === 'outdated' || $site->last_php_status === 'outdated')) {
            $risks[] = 'A WordPress site reports outdated core or unsupported PHP.';
        }
        if ($webpageChecks->contains(fn ($check) => $check->last_status === 'broken')) {
            $risks[] = 'A registered frontend check is currently reporting a broken page.';
        }
        if ($uptime['percent'] !== null && $uptime['percent'] < $uptime['target']) {
            $risks[] = 'Observed availability for the reporting month was below the 99.9% target.';
        }
        if ($uptime['percent'] === null) {
            $risks[] = 'No historical uptime data is available for the reporting period; the 99.9% objective is not yet verifiable.';
        }
        if ($ec2->isNotEmpty() && ! $uptime['assets']->contains(fn ($asset) => $asset['type'] === 'ec2')) {
            $risks[] = 'No EC2 uptime observations were recorded; check IAM permissions for DescribeRegions and DescribeInstanceStatus.';
        }
        if ($sites->where('is_active', true)->isNotEmpty()) {
            $probedSiteIds = $uptime['checks']->where('asset_type', 'wordpress')->pluck('asset_key')->unique()->all();
            if ($sites->where('is_active', true)->contains(fn ($site) => ! in_array((string) $site->id, $probedSiteIds, true))) {
                $risks[] = 'At least one active WordPress site has no uptime probe in the reporting period.';
            }
        }
        if (! $costs['available']) {
            $risks[] = 'AWS cost data could not be retrieved; review Cost Explorer access and credentials.';
        }
        if ($users->contains(fn ($user) => ! $user->two_factor_confirmed_at)) {
            $risks[] = 'At least one dashboard user has not completed MFA enrollment.';
        }
        if ($risks === []) {
            $risks[] = 'No active risks were detected in the data currently available to the dashboard. Monitoring and source coverage still have limits; see report notes.';
        }

        return $risks;
    }
}