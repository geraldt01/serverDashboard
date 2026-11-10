<?php

namespace Tests\Feature;

use App\Mail\OtherServerPatchFailureMail;
use App\Models\OtherServer;
use App\Models\OtherServerPatchRun;
use App\Services\OtherServerPatchDryRun;
use Aws\Inspector2\Inspector2Client;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtherServerPatchDryRunTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $ssm;
    private MockHandler $inspector;
    private MockHandler $sts;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monitoring.mock_mode' => false, 'services.aws.region' => 'ap-southeast-2', 'services.patching.alert_email' => 'ops@example.com']);
        $this->ssm = new MockHandler();
        $this->inspector = new MockHandler();
        $this->sts = new MockHandler();
        foreach ([SsmClient::class => $this->ssm, Inspector2Client::class => $this->inspector, StsClient::class => $this->sts] as $class => $handler) {
            $this->app->bind($class, fn ($app, $params) => new $class(array_merge($params['args'], [
                'credentials' => $params['args']['credentials'] ?? ['key' => 'testing', 'secret' => 'testing'],
                'handler' => $handler,
            ])));
        }
        Mail::fake();
    }

    public function test_enqueuing_is_local_and_cannot_install_or_overlap(): void
    {
        $server = $this->server();
        $original = $server->getAttributes();
        $run = app(OtherServerPatchDryRun::class)->enqueue($server);
        $this->assertSame('queued', $run->status);
        $this->assertNull($this->ssm->getLastCommand());
        $this->assertSame($original, $server->fresh()->getAttributes());
        $this->expectExceptionMessage('already active');
        app(OtherServerPatchDryRun::class)->enqueue($server);
    }

    public function test_dry_run_targets_current_inspector_findings_and_waits_for_completion(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $this->prepareResponses($run, [$this->finding($run), $this->finding($run, '999999999999')]);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNull($run->completed_at);
        $this->assertSame(['libxml2'], $run->plan['packages']);
        $this->assertCount(1, $run->plan['findings']);
        $command = $this->ssm->getLastCommand();
        $this->assertSame([$run->instance_id], $command['InstanceIds']);
        $this->assertSame('AWS-RunShellScript', $command['DocumentName']);
        $this->assertStringContainsString('apt-get -s --only-upgrade --no-remove', $command['Parameters']['commands'][0]);
        $this->assertSame($run->instance_id, $this->inspector->getLastCommand()['filterCriteria']['resourceId'][0]['value']);
        $this->assertSame('123456789012', $this->inspector->getLastCommand()['filterCriteria']['awsAccountId'][0]['value']);

        $this->ssm->append(new Result(['Status' => 'InProgress']));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('running', $run->fresh()->status);
        $this->ssm->append(new Result(['Status' => 'Success', 'ResponseCode' => 0, 'StandardOutputContent' => $this->successOutput($run)]));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('dry_run_passed', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_target);
        Mail::assertNothingSent();
    }

    public function test_service_failure_sends_an_actionable_email_only_once(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $this->prepareResponses($run, [$this->finding($run)]);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->ssm->append(new Result(['Status' => 'Failed', 'ResponseCode' => 1,
            'StandardOutputContent' => "SD_DRY_RUN_{$run->id}:SERVICE_FAIL:nginx\nSD_DRY_RUN_{$run->id}:DONE\n"]));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        Mail::assertSent(OtherServerPatchFailureMail::class, function ($mail) {
            $this->assertStringContainsString('Critical service nginx', $mail->run->error);
            $this->assertStringContainsString('restore the known-good configuration', $mail->run->error);
            return $mail->hasTo('ops@example.com');
        });
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        Mail::assertSent(OtherServerPatchFailureMail::class, 1);
    }

    public function test_truncated_ssm_success_is_a_failure_not_a_pass(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $this->prepareResponses($run, []);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->ssm->append(new Result(['Status' => 'Success', 'ResponseCode' => 0, 'StandardOutputContent' => 'truncated']));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        Mail::assertSent(OtherServerPatchFailureMail::class);
    }

    public function test_timeout_releases_target_and_alerts_without_contacting_aws(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $run->update(['deadline_at' => now()->subMinute()]);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($this->ssm->getLastCommand());
        Mail::assertSent(OtherServerPatchFailureMail::class);
    }

    public function test_missing_services_or_alert_address_blocks_the_dry_run(): void
    {
        config(['services.patching.alert_email' => null]);
        $this->expectExceptionMessage('PATCH_ALERT_EMAIL');
        app(OtherServerPatchDryRun::class)->enqueue($this->server());
    }

    public function test_no_services_never_produces_a_false_health_pass(): void
    {
        $server = $this->server();
        $server->update(['critical_services' => null]);
        $this->expectExceptionMessage('1-30 critical services');
        app(OtherServerPatchDryRun::class)->enqueue($server);
    }

    public function test_target_snapshot_survives_server_connection_edits(): void
    {
        $server = $this->server();
        $run = app(OtherServerPatchDryRun::class)->enqueue($server);
        $server->update(['aws_instance_id' => 'i-00000000000000000', 'critical_services' => 'mysql']);
        $this->prepareResponses($run, []);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame([$run->instance_id], $this->ssm->getLastCommand()['InstanceIds']);
        $this->assertSame(['nginx'], $run->services);
    }

    public function test_patch_now_enqueues_only_a_dry_run_and_rejects_install_requests(): void
    {
        $server = $this->server();
        $this->signIn();
        $this->post(route('other-servers.patch-now', $server), ['mode' => 'install'])->assertSessionHasErrors('mode');
        $this->assertSame(0, OtherServerPatchRun::count());
        $this->post(route('other-servers.patch-now', $server), ['mode' => 'dry_run'])
            ->assertRedirect(route('other-servers.index'))->assertSessionHas('status');
        $this->assertSame('queued', OtherServerPatchRun::first()->status);
        $this->assertNull($this->ssm->getLastCommand());
        $this->get(route('other-servers.index'))->assertOk()->assertSee('Live installation is blocked')
            ->assertSee('Patch now (dry run)')->assertSee('Install patches')
            ->assertSee('dry-run-result-actions', false)
            ->assertSee('Live installation remains disabled until a tested zero-downtime runbook exists.', false);
    }

    public function test_viewers_cannot_start_dry_runs_or_edit_critical_services(): void
    {
        $server = $this->server();
        $this->signIn('viewer');
        $this->post(route('other-servers.patch-now', $server))->assertForbidden();
        $this->post(route('other-servers.refresh-updates', $server))->assertForbidden();
        $this->post(route('other-servers.update-details', $server), ['name' => 'Staging', 'criticalServices' => 'mysql'])->assertForbidden();
        $this->assertSame(0, OtherServerPatchRun::count());
    }

    public function test_disabled_dry_run_explains_missing_configuration_beside_the_button(): void
    {
        $server = $this->server();
        $server->update(['critical_services' => null]);
        config(['services.patching.alert_email' => null]);
        $this->signIn();
        $this->get(route('other-servers.index'))->assertOk()
            ->assertSee('Dry run unavailable:')
            ->assertSee('Set critical service names in Edit server settings.')
            ->assertSee('Configure a valid PATCH_ALERT_EMAIL on the dashboard.')
            ->assertSee('disabled aria-describedby="dry-run-blockers-' . $server->id . '"', false)
            ->assertSee('Edit server settings / critical services');
    }

    public function test_configured_dry_run_button_is_enabled_and_mock_mode_is_explained(): void
    {
        $this->server();
        $this->signIn();
        $this->get(route('other-servers.index'))->assertOk()->assertDontSee('Dry run unavailable:');
        config(['services.monitoring.mock_mode' => true]);
        $this->get(route('other-servers.index'))->assertOk()
            ->assertSee('Dry run unavailable:')->assertSee('Disable AWS_SYNC_USE_MOCK on the dashboard.');
    }

    public function test_stale_queue_explains_scheduler_dependency_without_submitting_commands(): void
    {
        $server = $this->server();
        $run = app(OtherServerPatchDryRun::class)->enqueue($server);
        $run->update(['created_at' => now()->subMinutes(3)]);
        $this->signIn();
        $this->get(route('other-servers.index'))->assertOk()
            ->assertSee('Wait for the active dry run to complete.')
            ->assertSee('Waiting for the dashboard scheduler.')
            ->assertSee('Check that Laravel schedule:run executes every minute.');
        $this->assertNull($this->ssm->getLastCommand());
    }

    public function test_service_configuration_is_validated_and_legacy_edits_preserve_it(): void
    {
        $server = $this->server();
        $this->signIn();
        $this->post(route('other-servers.update-details', $server), ['name' => 'Staging', 'criticalServices' => 'nginx; reboot'])
            ->assertSessionHasErrors('criticalServices');
        $this->assertSame('nginx', $server->fresh()->critical_services);
        $this->post(route('other-servers.update-details', $server), ['name' => 'Staging'])->assertRedirect();
        $this->assertSame('nginx', $server->fresh()->critical_services);
        $this->post(route('other-servers.update-details', $server), ['name' => 'Staging', 'criticalServices' => 'nginx, php8.3-fpm'])->assertRedirect();
        $this->assertSame(['nginx', 'php8.3-fpm'], $server->fresh()->criticalServicesList());
    }

    public function test_inspector_access_failure_never_submits_a_command(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $this->inspector->append(new \Aws\Inspector2\Exception\Inspector2Exception('Inspector access denied', new \Aws\Command('ListFindings'), ['code' => 'AccessDeniedException']));
        $this->prepareResponses($run, []);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('DescribeInstanceInformation', $this->ssm->getLastCommand()->getName());
        $this->assertNull($run->fresh()->command_id);
        Mail::assertSent(OtherServerPatchFailureMail::class);
    }

    public function test_inspector_pagination_is_consumed_before_submission(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $this->inspector->append(new Result(['findings' => [$this->finding($run)], 'nextToken' => 'page-two']));
        $this->prepareResponses($run, []);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('page-two', $this->inspector->getLastCommand()['nextToken']);
        $this->assertCount(1, $run->fresh()->plan['findings']);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_unsupported_packages_and_no_fix_findings_remain_manual(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $rpm = $this->finding($run);
        $rpm['packageVulnerabilityDetails']['vulnerablePackages'][0]['packageManager'] = 'RPM';
        $noFix = $this->finding($run);
        $noFix['fixAvailable'] = 'NO';
        $code = $this->finding($run);
        $code['type'] = 'CODE_VULNERABILITY';
        $this->prepareResponses($run, [$rpm, $noFix, $code]);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertCount(3, $run->fresh()->plan['findings']);
        $this->assertSame([], $run->fresh()->plan['packages']);
        $this->assertStringNotContainsString('apt-get', $this->ssm->getLastCommand()['Parameters']['commands'][0]);
    }

    public function test_windows_uses_a_read_only_powershell_service_check(): void
    {
        $server = $this->server();
        $server->update(['critical_services' => 'W3SVC']);
        $run = app(OtherServerPatchDryRun::class)->enqueue($server);
        $this->sts->append(new Result(['Account' => '123456789012']));
        $this->ssm->append(new Result(['InstanceInformationList' => [[
            'InstanceId' => $run->instance_id, 'PingStatus' => 'Online', 'PlatformType' => 'Windows',
        ]]]));
        $this->inspector->append(new Result(['findings' => []]));
        $this->ssm->append(new Result(['Command' => ['CommandId' => '12345678-1234-1234-1234-123456789012']]));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('AWS-RunPowerShellScript', $this->ssm->getLastCommand()['DocumentName']);
        $this->ssm->append(new Result(['Status' => 'Success', 'ResponseCode' => 0,
            'StandardOutputContent' => "SD_DRY_RUN_{$run->id}:SERVICE_OK:W3SVC\nSD_DRY_RUN_{$run->id}:PREVIEW_MANUAL\nSD_DRY_RUN_{$run->id}:DONE\n"]));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('dry_run_passed', $run->fresh()->status);
    }

    public function test_cross_account_inspector_and_ssm_use_the_same_assumed_credentials(): void
    {
        $server = $this->server();
        $server->update(['aws_role_arn' => 'arn:aws:iam::123456789012:role/DryRunAccess']);
        $run = app(OtherServerPatchDryRun::class)->enqueue($server);
        $this->sts->append(new Result(['Credentials' => [
            'AccessKeyId' => 'target-account', 'SecretAccessKey' => 'testing', 'SessionToken' => 'target-session',
            'Expiration' => new \DateTimeImmutable('+15 minutes'),
        ]]));
        foreach ([SsmClient::class => $this->ssm, Inspector2Client::class => $this->inspector] as $class => $handler) {
            $this->app->bind($class, function ($app, $params) use ($class, $handler) {
                $this->assertSame('target-account', $params['args']['credentials']->getAccessKeyId());
                $this->assertSame('target-session', $params['args']['credentials']->getSecurityToken());
                return new $class(array_merge($params['args'], ['handler' => $handler]));
            });
        }
        $this->prepareResponses($run, [$this->finding($run)]);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('123456789012', $run->fresh()->account_id);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_failed_assume_role_does_not_fall_back_or_submit_commands(): void
    {
        $server = $this->server();
        $server->update(['aws_role_arn' => 'arn:aws:iam::123456789012:role/DryRunAccess']);
        $run = app(OtherServerPatchDryRun::class)->enqueue($server);
        $this->sts->append(new \Aws\Sts\Exception\StsException('Access denied', new \Aws\Command('AssumeRole'), ['code' => 'AccessDenied']));
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($this->ssm->getLastCommand());
        $this->assertNull($this->inspector->getLastCommand());
        Mail::assertSent(OtherServerPatchFailureMail::class);
    }

    public function test_mail_transport_failure_is_visible_and_retried(): void
    {
        $run = app(OtherServerPatchDryRun::class)->enqueue($this->server());
        $run->update(['deadline_at' => now()->subMinute()]);
        $pending = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->with('ops@example.com')->andReturn($pending);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        $this->assertNull($run->fresh()->alert_sent_at);
        $this->assertStringContainsString('Mail delivery failed', $run->fresh()->alert_error);
        Mail::swap($mailFake);
        $this->artisan('other-servers:process-patch-runs')->assertSuccessful();
        Mail::assertSent(OtherServerPatchFailureMail::class);
        $this->assertNotNull($run->fresh()->alert_sent_at);
        $this->assertNull($run->fresh()->alert_error);
    }

    public function test_mock_mode_never_fabricates_service_health(): void
    {
        $server = $this->server();
        config(['services.monitoring.mock_mode' => true]);
        $this->expectExceptionMessage('disabled in mock mode');
        app(OtherServerPatchDryRun::class)->enqueue($server);
    }

    private function signIn(string $role = 'admin'): void
    {
        $user = \App\Models\User::factory()->create(['role' => $role, 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $user->id])->actingAs($user);
    }

    private function server(): OtherServer
    {
        $server = new OtherServer(['name' => 'Staging', 'slug' => 'staging', 'aws_instance_id' => 'i-0123456789abcdef0',
            'critical_services' => 'nginx', 'is_active' => true, 'monitor_token_encrypted' => 'testing']);
        $server->monitor_token = hash('sha256', 'testing');
        $server->save();
        return $server->fresh();
    }

    private function prepareResponses(OtherServerPatchRun $run, array $findings): void
    {
        $this->sts->append(new Result(['Account' => '123456789012']));
        $this->ssm->append(new Result(['InstanceInformationList' => [[
            'InstanceId' => $run->instance_id, 'PingStatus' => 'Online', 'PlatformType' => 'Linux',
        ]]]));
        $this->inspector->append(new Result(['findings' => $findings]));
        $this->ssm->append(new Result(['Command' => ['CommandId' => '12345678-1234-1234-1234-123456789012']]));
    }

    private function finding(OtherServerPatchRun $run, string $account = '123456789012'): array
    {
        return ['findingArn' => 'arn:aws:inspector2:ap-southeast-2:' . $account . ':finding/test',
            'awsAccountId' => $account, 'status' => 'ACTIVE', 'type' => 'PACKAGE_VULNERABILITY', 'fixAvailable' => 'YES',
            'title' => 'CVE-test - libxml2', 'severity' => 'HIGH',
            'resources' => [['id' => $run->instance_id, 'type' => 'AWS_EC2_INSTANCE', 'region' => $run->region]],
            'packageVulnerabilityDetails' => ['vulnerablePackages' => [[
                'name' => 'libxml2', 'version' => '1.0', 'fixedInVersion' => '2.0', 'packageManager' => 'DEB',
            ]]]];
    }

    private function successOutput(OtherServerPatchRun $run): string
    {
        return "SD_DRY_RUN_{$run->id}:SERVICE_OK:nginx\nSD_DRY_RUN_{$run->id}:PREVIEW_OK\nSD_DRY_RUN_{$run->id}:DONE\n";
    }
}