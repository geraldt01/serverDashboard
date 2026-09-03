<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WordpressCoreUpdate;
use App\Models\WordpressLoginEvent;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use App\Notifications\UnauthorizedWordpressLoginNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_a_registered_site_can_report_a_core_update(): void
    {
        $site = $this->createSite();

        $response = $this->signedReport($site, [
            'plugins' => [],
            'core' => [
                'currentVersion' => '6.5',
                'latestVersion' => '6.6',
                'status' => 'outdated',
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('wordpress_core_updates', [
            'wordpress_site_id' => $site->id,
            'current_version' => '6.5',
            'latest_version' => '6.6',
            'status' => 'outdated',
        ]);
        $this->assertSame(1, WordpressCoreUpdate::count());
    }

    public function test_a_registered_site_can_report_a_wp_admin_login(): void
    {
        $site = $this->createSite();

        $response = $this->signedLogin($site, [
            'username' => 'admin',
            'ipAddress' => '203.0.113.7',
            'userAgent' => 'Mozilla/5.0',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('wordpress_login_events', [
            'wordpress_site_id' => $site->id,
            'username' => 'admin',
            'ip_address' => '203.0.113.7',
        ]);
        $this->assertSame(1, WordpressLoginEvent::count());
    }

    public function test_a_login_from_a_whitelisted_ip_does_not_alert_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $site->update(['ip_whitelist' => "203.0.113.7\n198.51.100.0/24"]);

        $this->signedLogin($site, [
            'username' => 'admin',
            'ipAddress' => '203.0.113.7',
        ])->assertCreated();

        $this->assertDatabaseHas('wordpress_login_events', [
            'wordpress_site_id' => $site->id,
            'is_authorized' => true,
        ]);
        Notification::assertNothingSentTo($admin);
    }

    public function test_a_login_from_a_non_whitelisted_ip_alerts_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();
        $site->update(['ip_whitelist' => '203.0.113.7']);

        $this->signedLogin($site, [
            'username' => 'admin',
            'ipAddress' => '198.51.100.99',
        ])->assertCreated();

        $this->assertDatabaseHas('wordpress_login_events', [
            'wordpress_site_id' => $site->id,
            'is_authorized' => false,
        ]);
        Notification::assertSentTo($admin, UnauthorizedWordpressLoginNotification::class);
    }

    public function test_a_login_is_not_flagged_when_no_whitelist_is_configured(): void
    {
        Notification::fake();
        User::factory()->create(['role' => 'admin']);
        $site = $this->createSite();

        $this->signedLogin($site, [
            'username' => 'admin',
            'ipAddress' => '198.51.100.99',
        ])->assertCreated();

        $this->assertDatabaseHas('wordpress_login_events', [
            'wordpress_site_id' => $site->id,
            'is_authorized' => null,
        ]);
        Notification::assertNothingSent();
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

    private function signedLogin(WordpressSite $site, array $payload, ?string $timestamp = null, ?string $nonce = null)
    {
        $timestamp ??= (string) now()->timestamp;
        $nonce ??= bin2hex(random_bytes(16));
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $site->monitoringToken());

        return $this->call('POST', "/ingest/wordpress/site/{$site->slug}/login", [], [], [], [
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