<?php

namespace Tests\Feature;

use App\Models\OtherServer;
use App\Models\MonthlyReportSetting;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\Exception\SsmException;
use Aws\Ssm\SsmClient;
use Aws\Sts\Exception\StsException;
use Aws\Sts\StsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtherServerReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_servers_use_ssm_with_their_own_instance_and_account_configuration(): void
    {
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2']);
        $sameAccount = $this->createServer('Same account server', 'same-account', 'same-account-token', [
            'aws_instance_id' => 'i-0123456789abcdef0',
        ]);
        $firstCrossAccount = $this->createServer('First cross-account server', 'first-cross-account', 'first-cross-token', [
            'aws_instance_id' => 'i-0123456789abcdef1',
            'aws_role_arn' => 'arn:aws:iam::111111111111:role/MonitoringAccess',
        ]);
        $secondCrossAccount = $this->createServer('Second cross-account server', 'second-cross-account', 'second-cross-token', [
            'aws_instance_id' => 'i-0123456789abcdef2',
            'aws_role_arn' => 'arn:aws:iam::222222222222:role/MonitoringAccess',
        ]);
        $stsHandler = new MockHandler();
        $ssmHandler = new MockHandler();
        $expectedServer = null;
        $stsCalls = 0;
        $this->app->bind(StsClient::class, function ($app, $parameters) use ($stsHandler, &$stsCalls) {
            $stsCalls++;

            return new StsClient(array_merge($parameters['args'], [
                'credentials' => ['key' => 'dashboard-testing', 'secret' => 'dashboard-testing'],
                'handler' => $stsHandler,
            ]));
        });
        $this->app->bind(SsmClient::class, function ($app, $parameters) use ($ssmHandler, &$expectedServer) {
            if ($expectedServer->aws_role_arn) {
                $this->assertSame('server-' . $expectedServer->id, $parameters['args']['credentials']->getAccessKeyId());
                $this->assertSame('session-' . $expectedServer->id, $parameters['args']['credentials']->getSecurityToken());
            } else {
                $this->assertArrayNotHasKey('credentials', $parameters['args']);
                $parameters['args']['credentials'] = ['key' => 'dashboard-testing', 'secret' => 'dashboard-testing'];
            }

            return new SsmClient(array_merge($parameters['args'], ['handler' => $ssmHandler]));
        });
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        foreach ([$sameAccount, $firstCrossAccount, $secondCrossAccount, $sameAccount] as $server) {
            $expectedServer = $server;
            $originalAttributes = $server->fresh()->getAttributes();

            foreach (['test-connection', 'refresh-updates'] as $action) {
                $previousStsCalls = $stsCalls;
                if ($server->aws_role_arn) {
                    $stsHandler->append(new Result(['Credentials' => [
                        'AccessKeyId' => 'server-' . $server->id,
                        'SecretAccessKey' => 'testing-secret',
                        'SessionToken' => 'session-' . $server->id,
                        'Expiration' => new \DateTimeImmutable('+15 minutes'),
                    ]]));
                }
                $ssmHandler->append(new Result($action === 'test-connection'
                    ? ['InstanceInformationList' => [['InstanceId' => $server->aws_instance_id, 'PingStatus' => 'Online']]]
                    : ['Command' => ['CommandId' => 'command-' . $server->id]]));
                $this->post(route('other-servers.' . $action, $server))
                    ->assertRedirect(route('other-servers.index'))
                    ->assertSessionHas('status', fn ($message) => str_contains($message, $server->name)
                        && str_contains($message, $action === 'test-connection' ? 'is Online' : 'command-' . $server->id));

                $command = $ssmHandler->getLastCommand();
                $this->assertSame($action === 'test-connection' ? 'DescribeInstanceInformation' : 'SendCommand', $command->getName());
                $this->assertSame([$server->aws_instance_id], $action === 'test-connection' ? $command['Filters'][0]['Values'] : $command['InstanceIds']);
                $this->assertSame($server->aws_role_arn ? $previousStsCalls + 1 : $previousStsCalls, $stsCalls);
                if ($server->aws_role_arn) {
                    $this->assertSame($server->aws_role_arn, $stsHandler->getLastCommand()['RoleArn']);
                }
                $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
            }
        }
    }

    public function test_admin_can_save_validate_and_clear_a_servers_aws_role_arn(): void
    {
        $roleArn = 'arn:aws:iam::744763754042:role/ServerDashboardNexusSsm';
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);
        $payload = ['name' => 'Nexus Configapp', 'awsInstanceId' => 'i-08a08aecda290733a', 'awsRoleArn' => $roleArn];

        $this->post(route('other-servers.store'), $payload)->assertRedirect(route('other-servers.index'));
        $server = OtherServer::where('slug', 'nexus-configapp')->firstOrFail();
        $this->assertSame($roleArn, $server->aws_role_arn);
        $token = $server->monitoringToken();
        $this->get(route('other-servers.index'))->assertOk()
            ->assertSee('name="awsRoleArn"', false)
            ->assertSee($roleArn);

        $changedRoleArn = 'arn:aws:iam::744763754042:role/monitoring/NexusAccess';
        $this->post(route('other-servers.update-details', $server), array_merge($payload, ['awsRoleArn' => $changedRoleArn]))
            ->assertRedirect(route('other-servers.index'));
        $this->assertSame($changedRoleArn, $server->fresh()->aws_role_arn);

        unset($payload['awsRoleArn']);
        $this->post(route('other-servers.update-details', $server), $payload)->assertRedirect(route('other-servers.index'));
        $this->assertSame($changedRoleArn, $server->fresh()->aws_role_arn);

        foreach (['not-an-arn', 'arn:aws:iam::744763754042:user/Someone', 'arn:aws:iam::123:role/Nexus'] as $invalidArn) {
            $this->post(route('other-servers.update-details', $server), array_merge($payload, ['awsRoleArn' => $invalidArn]))
                ->assertSessionHasErrors('awsRoleArn');
            $this->assertSame($changedRoleArn, $server->fresh()->aws_role_arn);
            $this->post(route('other-servers.store'), array_merge($payload, ['awsRoleArn' => $invalidArn]))
                ->assertSessionHasErrors('awsRoleArn');
            $this->assertSame(1, OtherServer::count());
        }

        $this->post(route('other-servers.update-details', $server), array_merge($payload, ['awsRoleArn' => '']))
            ->assertRedirect(route('other-servers.index'));
        $this->assertNull($server->fresh()->aws_role_arn);
        $this->assertSame($token, $server->fresh()->monitoringToken());
    }

    public function test_viewer_cannot_configure_or_use_a_cross_account_role(): void
    {
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token');
        $viewer = \App\Models\User::factory()->create(['role' => 'viewer', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $viewer->id])->actingAs($viewer);
        $payload = ['name' => $server->name, 'awsRoleArn' => 'arn:aws:iam::744763754042:role/ServerDashboardNexusSsm'];

        $this->post(route('other-servers.store'), $payload)->assertForbidden();
        $this->post(route('other-servers.update-details', $server), $payload)->assertForbidden();
        $this->post(route('other-servers.test-connection', $server))->assertForbidden();
        $this->post(route('other-servers.patch-now', $server))->assertForbidden();
        $this->assertNull($server->fresh()->aws_role_arn);
    }

    public function test_both_ssm_buttons_use_the_servers_assumed_role_credentials(): void
    {
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2']);
        $roleArn = 'arn:aws:iam::744763754042:role/ServerDashboardNexusSsm';
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token', [
            'aws_instance_id' => 'i-08a08aecda290733a',
            'aws_role_arn' => $roleArn,
        ]);
        $originalAttributes = $server->fresh()->getAttributes();
        $stsHandler = new MockHandler();
        $ssmHandler = new MockHandler();
        $this->app->bind(StsClient::class, function ($app, $parameters) use ($stsHandler) {
            $this->assertArrayNotHasKey('credentials', $parameters['args']);

            return new StsClient(array_merge($parameters['args'], [
                'credentials' => ['key' => 'dashboard-testing', 'secret' => 'dashboard-testing'],
                'handler' => $stsHandler,
            ]));
        });
        $this->app->bind(SsmClient::class, function ($app, $parameters) use ($ssmHandler) {
            $this->assertSame('nexus-testing', $parameters['args']['credentials']->getAccessKeyId());
            $this->assertSame('nexus-session-token', $parameters['args']['credentials']->getSecurityToken());
            $this->assertSame('ap-southeast-2', $parameters['args']['region']);

            return new SsmClient(array_merge($parameters['args'], ['handler' => $ssmHandler]));
        });
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        foreach (['test-connection', 'refresh-updates'] as $action) {
            $stsHandler->append(new Result(['Credentials' => [
                'AccessKeyId' => 'nexus-testing',
                'SecretAccessKey' => 'nexus-testing-secret',
                'SessionToken' => 'nexus-session-token',
                'Expiration' => new \DateTimeImmutable('+15 minutes'),
            ]]));
            $ssmHandler->append(new Result($action === 'test-connection'
                ? ['InstanceInformationList' => [['InstanceId' => $server->aws_instance_id, 'PingStatus' => 'Online']]]
                : ['Command' => ['CommandId' => 'nexus-command-id']]));
            $this->post(route('other-servers.' . $action, $server))
                ->assertRedirect(route('other-servers.index'))
                ->assertSessionHas('status', fn ($message) => str_contains($message, $action === 'test-connection' ? 'is Online' : 'nexus-command-id'));

            $stsCommand = $stsHandler->getLastCommand();
            $this->assertSame('AssumeRole', $stsCommand->getName());
            $this->assertSame($roleArn, $stsCommand['RoleArn']);
            $this->assertSame('serverdashboard-' . $server->id, $stsCommand['RoleSessionName']);
            $this->assertSame($action === 'test-connection' ? 'DescribeInstanceInformation' : 'SendCommand', $ssmHandler->getLastCommand()->getName());
            $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
        }
    }

    public function test_failed_role_assumption_does_not_fall_back_to_dashboard_credentials(): void
    {
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2']);
        $roleArn = 'arn:aws:iam::744763754042:role/ServerDashboardNexusSsm';
        $server = $this->createServer('Nexus Configapp', 'nexus-configapp', 'nexus-token', [
            'aws_instance_id' => 'i-08a08aecda290733a',
            'aws_role_arn' => $roleArn,
        ]);
        $originalAttributes = $server->fresh()->getAttributes();
        $handler = new MockHandler();
        $this->app->bind(StsClient::class, fn ($app, $parameters) => new StsClient(array_merge($parameters['args'], [
            'credentials' => ['key' => 'testing', 'secret' => 'testing'],
            'handler' => $handler,
        ])));
        $ssmCalls = 0;
        $this->app->bind(SsmClient::class, function () use (&$ssmCalls) {
            $ssmCalls++;
            throw new \RuntimeException('Unexpected SSM client creation.');
        });
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        foreach (['test-connection', 'refresh-updates'] as $action) {
            $handler->append(new StsException('Access denied', new Command('AssumeRole'), ['code' => 'AccessDenied']));
            $this->post(route('other-servers.' . $action, $server))
                ->assertRedirect(route('other-servers.index'))
                ->assertSessionHas('error', fn ($message) => str_contains($message, 'Unable to assume AWS role ' . $roleArn)
                    && str_contains($message, 'sts:AssumeRole'));
        }

        $this->assertSame(0, $ssmCalls);
        $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
    }

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
            $this->assertArrayNotHasKey('credentials', $parameters['args']);

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
        $this->app->bind(StsClient::class, function () {
            $this->fail('Missing instance ID and mock mode must not assume an AWS role.');
        });
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
        $server->update([
            'aws_instance_id' => 'i-0123456789abcdef0',
            'aws_role_arn' => 'arn:aws:iam::744763754042:role/ServerDashboardNexusSsm',
        ]);
        $originalAttributes = $server->fresh()->getAttributes();
        $this->post(route('other-servers.test-connection', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('status', fn ($message) => str_contains($message, '(Mock mode)')
                && str_contains($message, 'No AWS access was verified'));
        $this->assertSame($originalAttributes, $server->fresh()->getAttributes());
        $this->post(route('other-servers.refresh-updates', $server))
            ->assertRedirect(route('other-servers.index'))
            ->assertSessionHas('status', fn ($message) => str_contains($message, '(Mock mode)'));
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

        $this->post(route('other-servers.refresh-updates', $server))
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

        $this->post(route('other-servers.refresh-updates', $server))
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