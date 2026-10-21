<?php

namespace App\Services;

use App\Models\Ec2PatchStatus;
use App\Models\MonthlyReportSetting;
use App\Models\OtherServer;
use App\Models\OtherServerPatchReport;
use App\Models\SupportTicketChange;
use App\Models\User;
use App\Models\UptimeCheck;
use App\Models\WebpageCheck;
use App\Models\WordpressCoreUpdate;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use Carbon\CarbonImmutable;

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
        $wordpressUpdateHistory = $this->wordpressUpdateHistory();
        $ec2MonthRows = Ec2PatchStatus::query()->whereBetween('checked_at', [$period['start'], $period['end']->subSecond()])->orderBy('checked_at')->get();
        $patchHistory = $this->patchHistory();
        $webpageChecks = WebpageCheck::query()->where('is_active', true)->orderBy('name')->get();
        $users = User::query()->orderBy('name')->get(['name', 'email', 'role', 'two_factor_confirmed_at']);
        $ticketChanges = SupportTicketChange::query()->with(['ticket', 'actor'])
            ->whereBetween('created_at', [$period['start'], $period['end']->subSecond()])
            ->latest()->get();
        $currentTicketChanges = SupportTicketChange::query()->with(['ticket', 'actor'])
            ->whereBetween('created_at', [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()])
            ->latest()->get();
        $currentPluginRows = WordpressPluginUpdate::query()
            ->whereIn('id', WordpressPluginUpdate::query()->selectRaw('MAX(id)')->groupBy('wordpress_site_id', 'plugin_name'))
            ->get();
        $currentCoreRows = WordpressCoreUpdate::query()
            ->whereIn('id', WordpressCoreUpdate::query()->selectRaw('MAX(id)')->groupBy('wordpress_site_id'))
            ->get();
        $outdatedPlugins = $currentPluginRows
            ->unique(fn ($row) => $row->wordpress_site_id.':'.$row->plugin_name)
            ->where('status', 'outdated')->values();
        $outdatedCore = $currentCoreRows
            ->unique('wordpress_site_id')
            ->where('status', 'outdated')->values();
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
        $costs = app(AwsCostReport::class)->build();
        $inspector = app(AwsInspectorReport::class)->build();
        $awsBackups = app(AwsBackupReport::class)->build();

        return [
            'period' => $period,
            'ec2' => ['latest' => $latestEc2, 'count' => $ec2Uptime->count(), 'patch_instances' => $latestEc2->count(), 'patches_pending' => $patchesPending, 'security_pending' => $securityPatchesPending],
            'ec2_inventory' => $ec2Uptime,
            'patch_history' => $patchHistory,
            'wordpress_update_history' => $wordpressUpdateHistory,
            'servers' => $serverInventory,
            'sites' => $sites,
            'webpage_checks' => $webpageChecks,
            'ticket_changes' => $ticketChanges,
            'current_ticket_changes' => $currentTicketChanges,
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
            'inspector' => $inspector,
            'aws_backups' => $awsBackups,
            'backups' => [
                ['asset' => 'WordPress websites', 'policy' => 'Daily WP Engine backups (reported policy; not verified by this dashboard)', 'restore_test' => 'Quarterly restore test; latest completion not recorded'],
            ],
            'improvement' => $this->improvement($securityPatchesPending, $patchesPending, $siteUpdateFindings, $uptime),
            'risks' => $this->risks($latestEc2, $serverInventory, $sites, $webpageChecks, $users, $uptime, $costs, $inspector),
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

    private function patchHistory(): array
    {
        $since = CarbonImmutable::now()->subDays(30);
        $ec2Rows = Ec2PatchStatus::query()->where('checked_at', '>=', $since)->orderBy('checked_at')->orderBy('id')->get();
        $agentRows = OtherServerPatchReport::query()->with('server')->where('reported_at', '>=', $since)->orderBy('reported_at')->orderBy('id')->get();
        $history = [];

        foreach ($ec2Rows->groupBy(fn ($row) => json_encode([$row->aws_region, $row->instance_id])) as $rows) {
            $first = $rows->first();
            $previous = Ec2PatchStatus::query()
                ->where('aws_region', $first->aws_region)->where('instance_id', $first->instance_id)
                ->where('checked_at', '<', $since)->orderByDesc('checked_at')->orderByDesc('id')->first();

            foreach ($rows as $row) {
                $cleared = $previous ? max(0, $previous->missing_count - $row->missing_count) : 0;
                $securityCleared = $previous ? max(0, $previous->security_count - $row->security_count) : 0;
                if ($cleared > 0 || $securityCleared > 0) {
                    $history[] = [
                        'at' => $row->checked_at,
                        'server' => $row->instance_name,
                        'region' => $row->aws_region,
                        'source' => 'AWS SSM',
                        'summary' => "Fix observed: {$cleared} fewer pending patches; {$securityCleared} fewer pending security patches. {$row->missing_count} patches remain pending.",
                        'details' => 'Individual installed package details were not reported by SSM.',
                        'type' => 'resolution',
                    ];
                }
                $previous = $row;
            }
        }

        foreach ($agentRows->groupBy('other_server_id') as $rows) {
            $previous = OtherServerPatchReport::query()
                ->where('other_server_id', $rows->first()->other_server_id)
                ->where('reported_at', '<', $since)->orderByDesc('reported_at')->orderByDesc('id')->first();

            foreach ($rows as $row) {
                $cleared = $previous ? max(0, $previous->total_updates - $row->total_updates) : 0;
                $securityCleared = $previous ? max(0, $previous->security_updates - $row->security_updates) : 0;
                if ($cleared > 0 || $securityCleared > 0) {
                    $details = [];
                    if ($row->total_updates === 0 && $previous->update_details) {
                        $details[] = 'Previously pending updates, now cleared:'."\n".$previous->update_details;
                    }
                    if ($row->security_updates === 0 && $securityCleared > 0 && $previous->security_update_details) {
                        $details[] = 'Previously pending security updates, now cleared:'."\n".$previous->security_update_details;
                    }
                    $history[] = [
                        'at' => $row->reported_at,
                        'server' => $row->server?->name ?? 'Removed server',
                        'region' => null,
                        'source' => 'Server agent',
                        'summary' => "Fix observed: {$cleared} fewer pending updates; {$securityCleared} fewer pending security updates. {$row->total_updates} updates remain pending; reboot required: ".($row->reboot_required ? 'yes' : 'no').'.',
                        'details' => implode("\n", $details) ?: 'Individual resolved packages cannot be identified from the available reports.',
                        'type' => 'resolution',
                    ];
                }
                $previous = $row;
            }
        }

        usort($history, fn ($left, $right) => $right['at']->getTimestamp() <=> $left['at']->getTimestamp());

        return array_slice($history, 0, 100);
    }

    private function wordpressUpdateHistory(): array
    {
        $since = CarbonImmutable::now()->subDays(30);
        $history = [];

        foreach (['Plugin' => WordpressPluginUpdate::class, 'Core' => WordpressCoreUpdate::class] as $type => $model) {
            $recentRows = $model::query()->with('wordpressSite:id,name')
                ->where('checked_at', '>=', $since)->orderBy('checked_at')->orderBy('id')->get();
            $groups = $recentRows->groupBy(fn ($row) => json_encode([
                $row->wordpress_site_id ?? $row->site_name,
                $type === 'Plugin' ? $row->plugin_name : 'WordPress core',
            ]));

            foreach ($groups as $rows) {
                $first = $rows->first();
                $baseline = $model::query()->where('wordpress_site_id', $first->wordpress_site_id);
                if ($first->wordpress_site_id === null) {
                    $baseline->where('site_name', $first->site_name);
                }
                if ($type === 'Plugin') {
                    $baseline->where('plugin_name', $first->plugin_name);
                }
                $previous = $baseline->where('checked_at', '<', $since)
                    ->orderByDesc('checked_at')->orderByDesc('id')->first();

                foreach ($rows as $row) {
                    if ($previous && $previous->current_version && $row->current_version
                        && version_compare($row->current_version, $previous->current_version, '>')) {
                        $history[] = [
                            'at' => $row->checked_at,
                            'site' => $row->wordpressSite?->name ?? $row->site_name,
                            'component' => $type === 'Plugin' ? $row->plugin_name : 'WordPress core',
                            'type' => $type,
                            'current' => $previous->current_version,
                            'latest' => $row->current_version,
                            'details' => 'Installed version increased from '.$previous->current_version.' to '.$row->current_version.'.',
                        ];
                    }
                    $previous = $row;
                }
            }
        }

        usort($history, fn ($left, $right) => $right['at']->getTimestamp() <=> $left['at']->getTimestamp());

        return array_slice($history, 0, 100);
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

    private function risks($ec2, $servers, $sites, $webpageChecks, $users, array $uptime, array $costs, array $inspector): array
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
        if ($inspector['available'] && ($inspector['counts']['CRITICAL'] > 0 || $inspector['counts']['HIGH'] > 0)) {
            $risks[] = 'Amazon Inspector reports '.$inspector['counts']['CRITICAL'].' critical and '.$inspector['counts']['HIGH'].' high active package vulnerability finding(s).';
        }
        if (! $inspector['available'] || $inspector['partial']) {
            $risks[] = 'Amazon Inspector vulnerability coverage is unavailable or incomplete; review the Inspector section and AWS region permissions.';
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