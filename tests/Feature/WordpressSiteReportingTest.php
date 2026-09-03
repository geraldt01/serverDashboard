<?php

namespace Tests\Feature;

use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordpressSiteReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_site_can_report_an_available_plugin_update(): void
    {
        $site = $this->createSite();

        $response = $this->signedReport($site, [
            'plugins' => [[
                'pluginName' => 'example-plugin/example-plugin.php',
                'currentVersion' => '1.0.0',
                'latestVersion' => '1.2.0',
                'status' => 'outdated',
            ]],
        ]);

        $response->assertCreated()->assertJson(['inserted' => 1]);

        $this->assertDatabaseHas('wordpress_plugin_updates', [
            'wordpress_site_id' => $site->id,
            'plugin_name' => 'example-plugin/example-plugin.php',
            'status' => 'outdated',
        ]);

        $this->assertSame(1, WordpressPluginUpdate::count());
        $this->assertDatabaseHas('wordpress_sites', [
            'id' => $site->id,
            'last_plugin_count' => 1,
            'last_outdated_count' => 1,
        ]);
    }

    public function test_a_missing_or_invalid_site_token_is_rejected(): void
    {
        $site = $this->createSite();

        $this->postJson("/ingest/wordpress/site/{$site->slug}", ['plugins' => []])
            ->assertUnauthorized();
    }

    public function test_a_signed_request_nonce_cannot_be_replayed(): void
    {
        $site = $this->createSite();
        $payload = [
            'plugins' => [[
                'pluginName' => 'example-plugin/example-plugin.php',
                'currentVersion' => '1.0.0',
                'latestVersion' => '1.0.0',
                'status' => 'up_to_date',
            ]],
        ];
        $timestamp = (string) now()->timestamp;
        $nonce = '11111111111111111111111111111111';

        $this->signedReport($site, $payload, $timestamp, $nonce)->assertCreated();
        $this->signedReport($site, $payload, $timestamp, $nonce)->assertUnauthorized();
    }

    public function test_an_expired_signed_request_is_rejected(): void
    {
        $site = $this->createSite();

        $this->signedReport($site, ['plugins' => []], (string) now()->subMinutes(6)->timestamp)
            ->assertUnauthorized();
    }

    private function signedReport(WordpressSite $site, array $payload, ?string $timestamp = null, ?string $nonce = null)
    {
        $timestamp ??= (string) now()->timestamp;
        $nonce ??= bin2hex(random_bytes(16));
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $site->monitoringToken());

        return $this->call('POST', "/ingest/wordpress/site/{$site->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WORDPRESS_MONITOR_TIMESTAMP' => $timestamp,
            'HTTP_X_WORDPRESS_MONITOR_NONCE' => $nonce,
            'HTTP_X_WORDPRESS_MONITOR_SIGNATURE' => $signature,
        ], $body);
    }

    private function createSite(): WordpressSite
    {
        $site = new WordpressSite([
            'name' => 'Example WordPress',
            'slug' => 'example-wordpress',
            'url' => 'https://example.test',
        ]);
        $site->setMonitoringToken('site-monitor-token-for-test');
        $site->save();

        return $site;
    }
}