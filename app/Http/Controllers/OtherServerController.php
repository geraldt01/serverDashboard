<?php

namespace App\Http\Controllers;

use App\Models\OtherServer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OtherServerController extends Controller
{
    public function index()
    {
        return view('other-servers.index', [
            'otherServers' => OtherServer::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hostname' => ['nullable', 'string', 'max:255'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'server';
        $slug = $baseSlug;
        $suffix = 2;

        while (OtherServer::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $suffix++;
        }

        $token = Str::random(48);
        $server = new OtherServer([
            'name' => $validated['name'],
            'slug' => $slug,
            'hostname' => $validated['hostname'] ?? null,
        ]);
        $server->setMonitoringToken($token);
        $server->save();

        return redirect()->route('other-servers.index')
            ->with('status', "Server {$server->name} added. Copy the generated credentials now; the token will not be shown again.")
            ->with('otherServerCredentials', $this->credentialsFor($server, $token));
    }

    public function rotateToken(OtherServer $otherServer)
    {
        $token = Str::random(48);
        $otherServer->setMonitoringToken($token);
        $otherServer->save();

        return redirect()->route('other-servers.index')
            ->with('status', "The token for {$otherServer->name} was rotated. Update the agent config now; the previous token no longer works.")
            ->with('otherServerCredentials', $this->credentialsFor($otherServer, $token));
    }

    public function toggleActive(OtherServer $otherServer)
    {
        $otherServer->update(['is_active' => ! $otherServer->is_active]);
        $state = $otherServer->is_active ? 'enabled' : 'disabled';

        return redirect()->route('other-servers.index')->with('status', "Reporting for {$otherServer->name} is {$state}.");
    }

    public function report(Request $request, OtherServer $otherServer)
    {
        $validated = $request->validate([
            'osName' => ['nullable', 'string', 'max:120'],
            'totalUpdates' => ['required', 'integer', 'min:0', 'max:100000'],
            'securityUpdates' => ['required', 'integer', 'min:0', 'max:100000', 'lte:totalUpdates'],
            'rebootRequired' => ['required', 'boolean'],
            'checkedAt' => ['nullable', 'date'],
        ]);

        $otherServer->update([
            'os_name' => $validated['osName'] ?? $otherServer->os_name,
            'total_updates' => $validated['totalUpdates'],
            'security_updates' => $validated['securityUpdates'],
            'reboot_required' => $validated['rebootRequired'],
            'last_reported_at' => $validated['checkedAt'] ?? now(),
        ]);

        return response()->json(['message' => 'Server update report saved.'], 201);
    }

    private function credentialsFor(OtherServer $server, string $token): array
    {
        return [
            'serverName' => $server->name,
            'endpoint' => route('other-servers.report', ['otherServer' => $server->slug]),
            'token' => $token,
        ];
    }
}
