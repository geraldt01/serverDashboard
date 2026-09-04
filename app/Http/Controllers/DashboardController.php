<?php

namespace App\Http\Controllers;

use App\Models\Ec2PatchStatus;
use App\Models\OtherServer;
use App\Models\TrafficEvent;
use App\Models\User;
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
        $trafficRows = TrafficEvent::query()
            ->selectRaw('DATE(recorded_at) as day, site_name, SUM(visits) as visits')
            ->where('recorded_at', '>=', now()->subDays(14))
            ->groupByRaw('DATE(recorded_at), site_name')
            ->orderBy('day')
            ->get();

        $latestPluginIds = WordpressPluginUpdate::query()
            ->selectRaw('MAX(id)')
            ->groupBy('site_name', 'plugin_name');

        $latestEc2Ids = Ec2PatchStatus::query()
            ->selectRaw('MAX(id)')
            ->groupBy('instance_id');

        $latestCoreIds = WordpressCoreUpdate::query()
            ->selectRaw('MAX(id)')
            ->groupBy('site_name');

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

        $ec2Rows = $instances->map(fn (Ec2PatchStatus $instance) => (object) [
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

        $otherServerRows = OtherServer::query()
            ->where('is_active', true)
            ->orderByDesc('security_updates')
            ->orderBy('name')
            ->get()
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

        return view('dashboard', [
            'trafficLast24h' => TrafficEvent::query()->where('recorded_at', '>=', now()->subDay())->sum('visits'),
            'outdatedPlugins' => $plugins->where('status', 'outdated')->count(),
            'ec2MissingPatches' => $patchInstances->sum('missing_count'),
            'outdatedCoreSites' => $coreUpdates->where('status', 'outdated')->count(),
            'trafficRows' => $trafficRows,
            'plugins' => $plugins,
            'instances' => $patchInstances,
            'coreUpdates' => $coreUpdates,
            'recentLogins' => $recentLogins,
            'wordpressSites' => $request->user()->isAdmin()
                ? WordpressSite::query()->orderBy('name')->get()
                : collect(),
            'users' => $request->user()->isAdmin()
                ? User::query()->orderByDesc('created_at')->get(['id', 'name', 'email', 'role', 'created_at'])
                : collect(),
        ]);
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
