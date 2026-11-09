<?php

namespace Tests\Feature;

use App\Models\OtherServer;
use App\Models\MonthlyReportSetting;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\Exception\SsmException;
use Aws\Ssm\SsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtherServerReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_checks_ssm_status_without_a_hostname_or_running_commands(): void
    {
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2']);
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token', [
            'aws_instance_id' => 'i-0123456789abcdef0',
        ]);
        $originalAttributes = $server->fresh()->getAttributes();
        $handler = new MockHandler();
        $this->app->bind(SsmClient::class, function ($app, $parameters) use ($handler) {
            $this->assertSame('ap-southeast-2', $parameters['args']['region']);

            return new SsmClient(array_merge($parameters['args'], [
                'credentials' => ['key' => 'testing', 'secret' => 'testing'],
                'handler' => $handler,
            ]));
        });
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        foreach (['Online', 'ConnectionLost', 'Inactive', null] as $pingStatus) {
            $node = ['InstanceId' => $server->aws_instance_id];
            if ($pingStatus !== null) {
                $node['PingStatus'] = $pingStatus;
            }
            $handler->append(new Result(['InstanceInformationList' => [$node]]));
            $response = $this->post(route('other-servers.test-connection', $server))
                ->assertRedirect(route('other-servers.index'));

            if ($pingStatus === 'Online') {
                $response->assertSessionHas('status', fn ($message) => str_contains($message, 'is Online'));
                $this->get(route('other-servers.index'))->assertOk()
                    ->assertSee('Check SSM managed-node status without SSH')
                    ->assertDontSee('TCP port 22');
            } else {
                $response->assertSessionHas('error', fn ($message) => str_contains($message, 'ping status is ' . ($pingStatus ?? 'Unknown')));
            }

            $command = $handler->getLastCommand();
            $this->assertSame('DescribeInstanceInformation', $command->getName());
            $this->assertSame([['Key' => 'InstanceIds', 'Values' => [$server->aws_instance_id]]], $command['Filters']);
            $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
        }

        $handler->append(new Result(['InstanceInformationList' => []]));
        $this->post(route('other-servers.test-connection', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'not registered')
                && str_contains($message, 'ap-southeast-2')
                && str_contains($message, 'cross-account role access'));

        $handler->append(new SsmException('Access denied', new Command('DescribeInstanceInformation'), ['code' => 'AccessDeniedException']));
        $this->post(route('other-servers.test-connection', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ssm:DescribeInstanceInformation')
                && str_contains($message, 'Access denied'));
        $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
    }

    public function test_connection_requires_an_instance_id_and_mock_mode_does_not_contact_aws(): void
    {
        $this->app->bind(SsmClient::class, function () {
            $this->fail('Missing instance ID and mock mode must not contact AWS.');
        });
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token', ['hostname' => 'example.com']);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        $this->post(route('other-servers.test-connection', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'no AWS instance ID'));
        $this->get(route('other-servers.index'))->assertOk()
            ->assertSee('Set an AWS instance ID to test SSM access');

        config(['services.monitoring.mock_mode' => true]);
        $server->update(['aws_instance_id' => 'i-0123456789abcdef0']);
        $originalAttributes = $server->fresh()->getAttributes();
        $this->post(route('other-servers.test-connection', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('status', fn ($message) => str_contains($message, '(Mock mode)')
                && str_contains($message, 'No AWS access was verified'));
        $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
    }

    public function test_adding_a_server_does_not_require_aws_ssm(): void
    {
        $this->app->bind(SsmClient::class, function () {
            $this->fail('Registration must not contact AWS SSM.');
        });
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        $this->post(route('other-servers.store'), [
            'name' => 'Nexus Configapp',
            'awsInstanceId' => 'i-0123456789abcdef0',
        ])->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('otherServerCredentials.token')
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('other_servers', [
            'name' => 'Nexus Configapp',
            'aws_instance_id' => 'i-0123456789abcdef0',
        ]);
    }

    public function test_patch_check_sends_the_reporting_script_to_the_configured_instance(): void
    {
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2']);
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token', [
            'aws_instance_id' => 'i-0123456789abcdef0',
        ]);
        $handler = new MockHandler();
        $handler->append(new Result(['Command' => ['CommandId' => 'test-command-id']]));
        $this->app->bind(SsmClient::class, function ($app, $parameters) use ($handler) {
            $this->assertSame('ap-southeast-2', $parameters['args']['region']);

            return new SsmClient(array_merge($parameters['args'], [
                'credentials' => ['key' => 'testing', 'secret' => 'testing'],
                'handler' => $handler,
            ]));
        });
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        $this->post(route('other-servers.patch-now', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'test-command-id'))
            ->assertSessionMissing('error');

        $command = $handler->getLastCommand();
        $this->assertSame('SendCommand', $command->getName());
        $this->assertSame([$server->aws_instance_id], $command['InstanceIds']);
        $this->assertSame('AWS-RunShellScript', $command['DocumentName']);
        $this->assertSame(['/usr/local/bin/serverdashboard-agent.sh'], $command['Parameters']['commands']);
        $this->assertNull($server->fresh()->last_reported_at);
    }

    public function test_invalid_ssm_instance_shows_setup_guidance_without_changing_server_data(): void
    {
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2']);
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token', [
            'aws_instance_id' => 'i-0123456789abcdef0',
            'total_updates' => 7,
            'security_updates' => 2,
        ]);
        $originalAttributes = $server->fresh()->getAttributes();
        $handler = new MockHandler();
        $handler->append(new SsmException('Instances not in a valid state for account', new Command('SendCommand'), [
            'code' => 'InvalidInstanceId',
        ]));
        $client = new SsmClient([
            'version' => 'latest',
            'region' => 'ap-southeast-2',
            'credentials' => ['key' => 'testing', 'secret' => 'testing'],
            'handler' => $handler,
        ]);
        $this->app->bind(SsmClient::class, fn () => $client);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        $this->post(route('other-servers.patch-now', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionMissing('status')
            ->assertSessionHas('error', function ($message) {
                return str_contains($message, 'i-0123456789abcdef0')
                    && str_contains($message, 'ap-southeast-2')
                    && str_contains($message, 'server remains registered')
                    && str_contains($message, 'AmazonSSMManagedInstanceCore');
            });

        $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
        $this->get(route('other-servers.index'))->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Systems Manager Managed nodes')
            ->assertDontSee('Instances not in a valid state for account');
    }

    public function test_admin_can_permanently_remove_a_registered_server_and_its_history(): void
    {
        $server = $this->createServer('Retired server', 'retired-server', 'retired-token');
        $retained = $this->createServer('Retained server', 'retained-server', 'retained-token');
        $this->sendReport($server, 'retired-token', [
            'totalUpdates' => 2,
            'securityUpdates' => 1,
            'rebootRequired' => false,
        ])->assertCreated();
        $this->assertDatabaseHas('other_server_patch_reports', ['other_server_id' => $server->id]);
        $this->assertDatabaseHas('other_server_report_nonces', ['other_server_id' => $server->id]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);
        $this->get(route('other-servers.index'))->assertOk()
            ->assertDontSee('Remove server')
            ->assertDontSee('data-delete-server', false)
            ->assertDontSee('>Disable</button>', false);
        $this->delete(route('other-servers.destroy', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('other_servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('other_server_patch_reports', ['other_server_id' => $server->id]);
        $this->assertDatabaseMissing('other_server_report_nonces', ['other_server_id' => $server->id]);
        $this->assertDatabaseHas('other_servers', ['id' => $retained->id]);
        $this->sendReport($server, 'retired-token', [
            'totalUpdates' => 0,
            'securityUpdates' => 0,
            'rebootRequired' => false,
        ])->assertNotFound();
    }

    public function test_viewer_cannot_remove_a_registered_server(): void
    {
        $server = $this->createServer('Protected server', 'protected-server', 'protected-token');
        $viewer = \App\Models\User::factory()->create(['role' => 'viewer', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $viewer->id])->actingAs($viewer);

        $this->delete(route('other-servers.destroy', $server))->assertForbidden();
        $this->assertDatabaseHas('other_servers', ['id' => $server->id]);
    }

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
        $this->assertDatabaseHas('other_server_patch_reports', [
            'other_server_id' => $firstServer->id,
            'total_updates' => 1,
            'security_updates' => 1,
            'update_details' => 'first-package 1.2',
        ]);
        $this->assertDatabaseHas('other_servers', [
            'id' => $secondServer->id,
            'update_details' => 'second-package 4.5',
            'security_update_details' => null,
        ]);
        $this->assertDatabaseHas('other_server_patch_reports', [
            'other_server_id' => $secondServer->id,
            'update_details' => 'second-package 4.5',
        ]);

        $reportSettings = MonthlyReportSetting::current();
        $this->get(route('monthly-reports.public', ['token' => $reportSettings->public_token]))
            ->assertOk()
            ->assertSee('Recent patch history · last 30 days')
            ->assertSee('first-package 1.2')
            ->assertSee('second-package 4.5');
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