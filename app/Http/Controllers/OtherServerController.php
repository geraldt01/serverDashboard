<?php

namespace App\Http\Controllers;

use App\Models\OtherServer;
use Aws\Ssm\SsmClient;
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
            'awsInstanceId' => ['nullable', 'string', 'regex:/^i-[0-9a-f]{8,17}$/'],
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
            'aws_instance_id' => $validated['awsInstanceId'] ?? null,
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

    public function testConnection(OtherServer $otherServer)
    {
        $hostname = trim((string) $otherServer->hostname);

        if ($hostname === '') {
            return redirect()->route('other-servers.index')
                ->with('status', "Cannot test {$otherServer->name}: no hostname/EC2 address is set for this server.");
        }

        $ip = $this->resolveIp($hostname);

        if ($ip === null) {
            return redirect()->route('other-servers.index')
                ->with('status', "Connection test for {$otherServer->name} failed: \"{$hostname}\" could not be resolved via DNS from this server. Verify the hostname is correct and that this server has outbound DNS access.");
        }

        // RFC1918 private ranges are allowed (EC2 public hostnames resolve to the private VPC IP when queried from inside the same VPC).
        // Still block loopback/link-local so this can't be used to probe the dashboard's own host or the cloud metadata service (169.254.169.254).
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
            return redirect()->route('other-servers.index')
                ->with('status', "Connection test for {$otherServer->name} blocked: \"{$hostname}\" resolves to {$ip}, a reserved/link-local address.");
        }

        $port = 22;
        $connection = @fsockopen($ip, $port, $errno, $errstr, 5);

        if ($connection) {
            fclose($connection);

            return redirect()->route('other-servers.index')
                ->with('status', "Connection test succeeded: {$otherServer->name} ({$hostname}:{$port}) is reachable.");
        }

        return redirect()->route('other-servers.index')
            ->with('status', "Connection test failed: {$otherServer->name} ({$hostname}:{$port}) is not reachable ({$errstr}).");
    }

    /**
     * Trigger an immediate patch/update check on the instance via AWS Systems Manager Run Command.
     * No SSH keys are stored by the dashboard; execution relies on the instance's own SSM Agent and IAM role.
     */
    public function patchNow(OtherServer $otherServer)
    {
        $instanceId = trim((string) $otherServer->aws_instance_id);

        if ($instanceId === '') {
            return redirect()->route('other-servers.index')
                ->with('status', "Cannot patch {$otherServer->name}: no AWS instance ID is set for this server.");
        }

        if (config('services.monitoring.mock_mode')) {
            return redirect()->route('other-servers.index')
                ->with('status', "(Mock mode) Patch check triggered for {$otherServer->name}. It will report new figures on its next push.");
        }

        try {
            $ssm = new SsmClient(['version' => 'latest', 'region' => config('services.ses.region')]);
            $result = $ssm->sendCommand([
                'InstanceIds' => [$instanceId],
                'DocumentName' => 'AWS-RunShellScript',
                'Comment' => "ServerDashboard manual patch check: {$otherServer->name}",
                'Parameters' => ['commands' => ['/usr/local/bin/serverdashboard-agent.sh']],
                'TimeoutSeconds' => 60,
            ]);

            $commandId = $result['Command']['CommandId'] ?? null;

            return redirect()->route('other-servers.index')
                ->with('status', "Patch check triggered for {$otherServer->name} via AWS SSM (command {$commandId}). Updated figures should appear within a minute.");
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('other-servers.index')
                ->with('status', "Failed to trigger patch check for {$otherServer->name}: {$exception->getMessage()}");
        }
    }

    /**
     * Resolve a hostname to an IPv4 address, falling back to a direct DNS query
     * if the system resolver (gethostbyname) can't reach it.
     */
    private function resolveIp(string $hostname): ?string
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return $hostname;
        }

        $viaSystemResolver = gethostbyname($hostname);
        if ($viaSystemResolver !== $hostname && filter_var($viaSystemResolver, FILTER_VALIDATE_IP)) {
            return $viaSystemResolver;
        }

        foreach (@dns_get_record($hostname, DNS_A) ?: [] as $record) {
            if (isset($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                return $record['ip'];
            }
        }

        return null;
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
