<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WordpressCoreUpdate;
use App\Models\WordpressLoginEvent;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use App\Notifications\UnauthorizedWordpressLoginNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class WordpressSiteController extends Controller
{
    public function index()
    {
        return view('wordpress-sites.index', [
            'wordpressSites' => WordpressSite::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048', 'unique:wordpress_sites,url'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'wordpress-site';
        $slug = $baseSlug;
        $suffix = 2;

        while (WordpressSite::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $suffix++;
        }

        $token = Str::random(48);
        $site = new WordpressSite([
            'name' => $validated['name'],
            'slug' => $slug,
            'url' => rtrim($validated['url'], '/'),
        ]);
        $site->setMonitoringToken($token);
        $site->save();

        return redirect()->route('wordpress-sites.index')
            ->with('status', "WordPress site {$site->name} added. Copy the generated credentials now; the token will not be shown again.")
            ->with('wordpressSiteCredentials', $this->credentialsFor($site, $token));
    }

    public function rotateToken(WordpressSite $wordpressSite)
    {
        $token = Str::random(48);
        $wordpressSite->setMonitoringToken($token);
        $wordpressSite->save();

        return redirect()->route('wordpress-sites.index')
            ->with('status', "The token for {$wordpressSite->name} was rotated. Update the WordPress reporter now; the previous token no longer works.")
            ->with('wordpressSiteCredentials', $this->credentialsFor($wordpressSite, $token));
    }

    public function toggleActive(WordpressSite $wordpressSite)
    {
        $wordpressSite->update(['is_active' => ! $wordpressSite->is_active]);
        $state = $wordpressSite->is_active ? 'enabled' : 'disabled';

        return redirect()->route('wordpress-sites.index')->with('status', "WordPress reporting for {$wordpressSite->name} is {$state}.");
    }

    public function updateWhitelist(Request $request, WordpressSite $wordpressSite)
    {
        $validated = $request->validate([
            'ip_whitelist' => ['nullable', 'string', 'max:4000'],
        ]);

        $wordpressSite->update(['ip_whitelist' => $validated['ip_whitelist'] ?? null]);

        return redirect()->route('wordpress-sites.index')->with('status', "IP whitelist for {$wordpressSite->name} was updated.");
    }

    public function report(Request $request, WordpressSite $wordpressSite)
    {
        $validated = $request->validate([
            'plugins' => ['present', 'array', 'max:500'],
            'plugins.*.pluginName' => ['required', 'string', 'max:255'],
            'plugins.*.currentVersion' => ['required', 'string', 'max:80'],
            'plugins.*.latestVersion' => ['required', 'string', 'max:80'],
            'plugins.*.status' => ['required', 'in:up_to_date,outdated,unknown'],
            'core' => ['nullable', 'array'],
            'core.currentVersion' => ['required_with:core', 'string', 'max:20'],
            'core.latestVersion' => ['required_with:core', 'string', 'max:20'],
            'core.status' => ['required_with:core', 'in:up_to_date,outdated,unknown'],
        ]);

        $checkedAt = now();
        $records = collect($validated['plugins'])->map(fn (array $plugin) => [
            'wordpress_site_id' => $wordpressSite->id,
            'site_name' => $wordpressSite->name,
            'plugin_name' => $plugin['pluginName'],
            'current_version' => $plugin['currentVersion'],
            'latest_version' => $plugin['latestVersion'],
            'status' => $plugin['status'],
            'checked_at' => $checkedAt,
            'created_at' => $checkedAt,
            'updated_at' => $checkedAt,
        ])->all();

        if ($records !== []) {
            WordpressPluginUpdate::insert($records);
        }

        if (isset($validated['core'])) {
            WordpressCoreUpdate::create([
                'wordpress_site_id' => $wordpressSite->id,
                'site_name' => $wordpressSite->name,
                'current_version' => $validated['core']['currentVersion'],
                'latest_version' => $validated['core']['latestVersion'],
                'status' => $validated['core']['status'],
                'checked_at' => $checkedAt,
            ]);
        }

        $wordpressSite->update([
            'last_plugin_count' => count($records),
            'last_outdated_count' => collect($records)->where('status', 'outdated')->count(),
            'last_reported_at' => $checkedAt,
        ]);

        return response()->json([
            'message' => 'WordPress plugin report saved.',
            'inserted' => count($records),
        ], 201);
    }

    public function reportLogin(Request $request, WordpressSite $wordpressSite)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'ipAddress' => ['required', 'ip'],
            'userAgent' => ['nullable', 'string', 'max:255'],
            'loggedInAt' => ['nullable', 'date'],
        ]);

        $isWhitelisted = $wordpressSite->whitelistedIps() === []
            ? null
            : $wordpressSite->isIpWhitelisted($validated['ipAddress']);

        $loginEvent = WordpressLoginEvent::create([
            'wordpress_site_id' => $wordpressSite->id,
            'site_name' => $wordpressSite->name,
            'username' => $validated['username'],
            'ip_address' => $validated['ipAddress'],
            'is_authorized' => $isWhitelisted,
            'user_agent' => $validated['userAgent'] ?? null,
            'logged_in_at' => $validated['loggedInAt'] ?? now(),
        ]);

        if ($isWhitelisted === false) {
            $admins = User::query()->where('role', 'admin')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new UnauthorizedWordpressLoginNotification($loginEvent));
            }
        }

        return response()->json(['message' => 'WordPress login event saved.'], 201);
    }

    private function credentialsFor(WordpressSite $site, string $token): array
    {
        return [
            'siteName' => $site->name,
            'endpoint' => route('wordpress-sites.report', ['wordpressSite' => $site->slug]),
            'token' => $token,
        ];
    }
}
