<?php

namespace App\Http\Controllers;

use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use Illuminate\Http\Request;
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

    public function report(Request $request, WordpressSite $wordpressSite)
    {
        $validated = $request->validate([
            'plugins' => ['required', 'array', 'max:500'],
            'plugins.*.pluginName' => ['required', 'string', 'max:255'],
            'plugins.*.currentVersion' => ['required', 'string', 'max:80'],
            'plugins.*.latestVersion' => ['required', 'string', 'max:80'],
            'plugins.*.status' => ['required', 'in:up_to_date,outdated,unknown'],
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

    private function credentialsFor(WordpressSite $site, string $token): array
    {
        return [
            'siteName' => $site->name,
            'endpoint' => route('wordpress-sites.report', ['wordpressSite' => $site->slug]),
            'token' => $token,
        ];
    }
}
