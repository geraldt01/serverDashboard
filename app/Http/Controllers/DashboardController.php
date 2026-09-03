<?php

namespace App\Http\Controllers;

use App\Models\Ec2PatchStatus;
use App\Models\TrafficEvent;
use App\Models\User;
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

        return view('dashboard', [
            'trafficLast24h' => TrafficEvent::query()->where('recorded_at', '>=', now()->subDay())->sum('visits'),
            'outdatedPlugins' => $plugins->where('status', 'outdated')->count(),
            'ec2MissingPatches' => $instances->sum('missing_count'),
            'trafficRows' => $trafficRows,
            'plugins' => $plugins,
            'instances' => $instances,
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
