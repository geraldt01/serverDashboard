<?php

namespace App\Http\Controllers;

use App\Models\Ec2PatchStatus;
use App\Models\OtherServer;
use App\Models\TrafficEvent;
use App\Models\User;
use App\Models\WebpageCheck;
use App\Models\WordpressCoreUpdate;
use App\Models\WordpressLoginEvent;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $registeredSiteNames = $this->trafficSiteNamesForRegisteredWebpages();

        $trafficRows = TrafficEvent::query()
            ->selectRaw('DATE(recorded_at) as day, site_name, SUM(visits) as visits')
            ->where('recorded_at', '>=', now()->subDays(14))
            ->whereIn('site_name', $registeredSiteNames)
            ->groupByRaw('DATE(recorded_at), site_name')
            ->orderBy('day')
            ->get();

        $latestPluginIds = WordpressPluginUpdate::query()
            ->selectRaw('MAX(id)')
            ->groupBy('wordpress_site_id', 'plugin_name');

        $latestEc2Ids = Ec2PatchStatus::query()
            ->selectRaw('MAX(id)')
            ->groupBy('instance_id');

        $latestCoreIds = WordpressCoreUpdate::query()
            ->selectRaw('MAX(id)')
            ->groupBy('wordpress_site_id');

        $plugins = WordpressPluginUpdate::query()
            ->whereIn('id', $latestPluginIds)
            ->orderByRaw("CASE status WHEN 'outdated' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END")
            ->orderBy('site_name')
            ->get();

        $instances = Ec2PatchStatus::query()
            ->whereIn('id', $latestEc2Ids)
            ->orderByDesc('missing_count')
            ->orderBy('instance_name')
            ->get();

        $isAdmin = $request->user()->isAdmin();

        $otherServers = OtherServer::query()
            ->where('is_active', true)
            ->orderByDesc('security_updates')
            ->orderBy('name')
            ->get();

        // A server can be tracked both via AWS SSM sync and its own push agent; only
        // count it once, preferring the agent's more direct/up-to-date figures.
        $agentTrackedInstanceIds = $otherServers->pluck('aws_instance_id')->filter()->values();

        $ec2Rows = $instances
            ->reject(fn (Ec2PatchStatus $instance) => $agentTrackedInstanceIds->contains($instance->instance_id))
            ->map(fn (Ec2PatchStatus $instance) => (object) [
                'source' => 'AWS SSM',
                'name' => $instance->instance_name,
                'identifier' => $instance->instance_id,
                'os_version' => $instance->os_version,
                'missing_count' => $instance->missing_count,
                'security_count' => $instance->security_count,
                'installed_count' => $instance->installed_count,
                'failed_count' => $instance->failed_count,
                'reboot_required' => $instance->reboot_required,
                'checked_at' => $instance->checked_at,
            ]);

        $otherServerRows = $otherServers
            ->map(fn (OtherServer $server) => (object) [
                'source' => 'Agent',
                'name' => $server->name,
                'identifier' => $isAdmin ? ($server->hostname ?? $server->slug) : $server->slug,
                'os_version' => $server->os_name,
                'missing_count' => $server->total_updates,
                'security_count' => $server->security_updates,
                'installed_count' => null,
                'failed_count' => null,
                'reboot_required' => $server->reboot_required,
                'checked_at' => $server->last_reported_at,
            ]);

        $patchInstances = $ec2Rows->concat($otherServerRows)
            ->sortByDesc('missing_count')
            ->values();

        $coreUpdates = WordpressCoreUpdate::query()
            ->whereIn('id', $latestCoreIds)
            ->orderByRaw("CASE status WHEN 'outdated' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END")
            ->orderBy('site_name')
            ->get();

        $recentLogins = WordpressLoginEvent::query()
            ->latest('logged_in_at')
            ->limit(25)
            ->get();

        $webpageChecks = WebpageCheck::query()
            ->orderByRaw("CASE last_status WHEN 'broken' THEN 0 WHEN 'warning' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return view('dashboard', [
            'trafficLast24h' => TrafficEvent::query()
                ->where('recorded_at', '>=', now()->subDay())
                ->whereIn('site_name', $registeredSiteNames)
                ->sum('visits'),
            'outdatedPlugins' => $plugins->where('status', 'outdated')->count(),
            'ec2MissingPatches' => $patchInstances->sum('missing_count'),
            'outdatedCoreSites' => $coreUpdates->where('status', 'outdated')->count(),
            'webpageIssues' => $webpageChecks->whereIn('last_status', ['broken', 'warning'])->count(),
            'trafficRows' => $trafficRows,
            'plugins' => $plugins,
            'instances' => $patchInstances,
            'coreUpdates' => $coreUpdates,
            'recentLogins' => $recentLogins,
            'webpageChecks' => $webpageChecks,
            'wordpressSites' => $request->user()->isAdmin()
                ? WordpressSite::query()->orderBy('name')->get()
                : collect(),
            'users' => $request->user()->isAdmin()
                ? User::query()->orderByDesc('created_at')->get(['id', 'name', 'email', 'role', 'created_at'])
                : collect(),
        ]);
    }

    /**
     * Traffic trend should only reflect sites actually registered as Frontend Page Checks.
     * WordpressSite reports carry traffic under the site's own `name`, so match the two
     * registrations by hostname (the only field both admin-entered records share).
     *
     * @return array<int, string>
     */
    private function trafficSiteNamesForRegisteredWebpages(): array
    {
        $registeredHosts = WebpageCheck::query()
            ->where('is_active', true)
            ->pluck('url')
            ->map(fn ($url) => $this->normalizeHost($url))
            ->filter()
            ->unique();

        if ($registeredHosts->isEmpty()) {
            return [];
        }

        return WordpressSite::query()
            ->get(['name', 'url'])
            ->filter(fn (WordpressSite $site) => $registeredHosts->contains($this->normalizeHost($site->url)))
            ->pluck('name')
            ->all();
    }

    private function normalizeHost(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        return strtolower(preg_replace('/^www\./', '', $host));
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The provided credentials are incorrect.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
