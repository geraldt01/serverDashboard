<?php

namespace Tests\Feature;

use App\Models\OtherServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtherServerReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_reports_do_not_clear_saved_update_details(): void
    {
        $token = 'server-monitor-token';
        $server = $this->createServer('Staging server', 'staging-server', $token, [
            'update_details' => "package-a 1.2\npackage-b 3.4",
            'security_update_details' => 'package-a 1.2 security update',
        ]);

        $this->sendReport($server, $token, [
            'totalUpdates' => 2,
            'securityUpdates' => 1,
            'rebootRequired' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('other_servers', [
            'id' => $server->id,
            'update_details' => "package-a 1.2\npackage-b 3.4",
            'security_update_details' => 'package-a 1.2 security update',
        ]);
    }

    public function test_update_details_are_saved_to_the_server_that_reported_them(): void
    {
        $firstToken = 'first-server-token';
        $secondToken = 'second-server-token';
        $firstServer = $this->createServer('First server', 'first-server', $firstToken);
        $secondServer = $this->createServer('Second server', 'second-server', $secondToken);

        $this->sendReport($firstServer, $firstToken, [
            'totalUpdates' => 1,
            'securityUpdates' => 1,
            'updateDetails' => 'first-package 1.2',
            'securityUpdateDetails' => 'first-package 1.2 security update',
            'rebootRequired' => false,
        ])->assertCreated();
        $this->sendReport($secondServer, $secondToken, [
            'totalUpdates' => 1,
            'securityUpdates' => 0,
            'updateDetails' => 'second-package 4.5',
            'securityUpdateDetails' => '',
            'rebootRequired' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('other_servers', [
            'id' => $firstServer->id,
            'update_details' => 'first-package 1.2',
            'security_update_details' => 'first-package 1.2 security update',
        ]);
        $this->assertDatabaseHas('other_servers', [
            'id' => $secondServer->id,
            'update_details' => 'second-package 4.5',
            'security_update_details' => null,
        ]);
    }

    private function createServer(string $name, string $slug, string $token, array $attributes = []): OtherServer
    {
        $server = new OtherServer(array_merge([
            'name' => $name,
            'slug' => $slug,
            'monitor_token_encrypted' => $token,
        ], $attributes));
        $server->monitor_token = hash('sha256', $token);
        $server->save();

        return $server;
    }

    private function sendReport(OtherServer $server, string $token, array $payload)
    {
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(16));
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $content, $token);

        return $this->call('POST', "/ingest/other-server/{$server->slug}/report", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SERVER_MONITOR_TIMESTAMP' => $timestamp,
            'HTTP_X_SERVER_MONITOR_NONCE' => $nonce,
            'HTTP_X_SERVER_MONITOR_SIGNATURE' => $signature,
        ], $content);
    }
}