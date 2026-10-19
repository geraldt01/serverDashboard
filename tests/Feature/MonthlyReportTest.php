<?php

namespace Tests\Feature;

use App\Models\MonthlyReportSetting;
use App\Models\Ec2PatchStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketChange;
use App\Models\User;
use App\Models\WordpressCoreUpdate;
use App\Models\WordpressPluginUpdate;
use App\Models\WordpressSite;
use App\Console\Commands\SendMonthlyItSecurityReport;
use App\Mail\MonthlyItSecurityReportMail;
use App\Services\AwsCostReport;
use App\Services\AwsInspectorReport;
use App\Services\MonthlyItSecurityReport;
use Aws\Inspector2\Inspector2Client;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_history_only_shows_observed_fixes_at_the_resolution_report_time(): void
    {
        $server = \App\Models\OtherServer::forceCreate([
            'name' => 'Patch history server',
            'slug' => 'patch-history-server',
            'monitor_token' => hash('sha256', 'patch-history-token'),
            'is_active' => true,
        ]);
        $baseline = [
            'other_server_id' => $server->id,
            'total_updates' => 2,
            'security_updates' => 1,
            'reboot_required' => false,
            'update_details' => 'openssl upgrade',
            'security_update_details' => 'openssl security upgrade',
        ];
        \App\Models\OtherServerPatchReport::create($baseline + ['reported_at' => now()->subDays(31)]);
        $resolvedAt = now()->subDays(2)->startOfMinute();
        \App\Models\OtherServerPatchReport::create(array_replace($baseline, [
            'total_updates' => 0,
            'security_updates' => 0,
            'update_details' => null,
            'security_update_details' => null,
            'reported_at' => $resolvedAt,
        ]));
        \App\Models\OtherServerPatchReport::create(array_replace($baseline, [
            'update_details' => 'newly available package',
            'reported_at' => now()->subDay(),
        ]));
        \App\Models\OtherServerPatchReport::create($baseline + ['reported_at' => now()]);

        $history = app(MonthlyItSecurityReport::class)->build()['patch_history'];

        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['at']->equalTo($resolvedAt));
        $this->assertStringContainsString('2 fewer pending updates', $history[0]['summary']);
        $this->assertStringContainsString('openssl upgrade', $history[0]['details']);
        $this->assertStringNotContainsString('newly available package', $history[0]['details']);

        $settings = MonthlyReportSetting::current();
        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertSee('Fix observed at')
            ->assertSee('View more')
            ->assertSee('data-open-patch-details="patch-details-0"', false)
            ->assertSee('<dialog id="patch-details-0"', false)
            ->assertSee('data-close-patch-details', false)
            ->assertSee($resolvedAt->format('j M Y, H:i:s T'))
            ->assertSee('openssl upgrade')
            ->assertDontSee('newly available package');
    }

    public function test_ec2_patch_history_compares_each_instance_and_region_without_truncating_baselines(): void
    {
        $baseline = [
            'instance_id' => 'i-history',
            'aws_region' => 'ap-southeast-2',
            'instance_name' => 'EC2 history server',
            'missing_count' => 5,
            'security_count' => 2,
            'installed_count' => 100,
            'failed_count' => 0,
            'reboot_required' => false,
        ];
        Ec2PatchStatus::create($baseline + ['checked_at' => now()->subDays(31)]);
        $resolvedAt = now()->subDays(29)->startOfMinute();
        $resolved = array_replace($baseline, ['missing_count' => 3, 'security_count' => 1, 'installed_count' => 102]);
        Ec2PatchStatus::create($resolved + ['checked_at' => $resolvedAt]);
        foreach (range(1, 105) as $number) {
            Ec2PatchStatus::create($resolved + ['checked_at' => now()->subMinutes(110 - $number)]);
        }
        Ec2PatchStatus::create(array_replace($baseline, [
            'aws_region' => 'us-east-1',
            'missing_count' => 0,
            'security_count' => 0,
            'checked_at' => now(),
        ]));

        $history = app(MonthlyItSecurityReport::class)->build()['patch_history'];

        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['at']->equalTo($resolvedAt));
        $this->assertSame('ap-southeast-2', $history[0]['region']);
        $this->assertStringContainsString('2 fewer pending patches', $history[0]['summary']);
        $this->assertStringContainsString('1 fewer pending security patches', $history[0]['summary']);
    }

    public function test_public_report_requires_the_random_public_token(): void
    {
        $settings = MonthlyReportSetting::current();

        $this->get(route('monthly-reports.public', ['token' => str_repeat('a', 64)]))->assertNotFound();
        $response = $this->get(route('monthly-reports.public', ['token' => $settings->public_token]));
        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('IT &amp; Security Report', false)
            ->assertSee('AWS cost breakdown')
            ->assertSee('Backup policy')
            ->assertSee('Config App change log')
            ->assertSee('Systems, access and operating guide');
        $this->assertStringNotContainsString('<details', $response->getContent());
    }

    public function test_inspector_report_maps_active_vulnerability_findings_and_counts_severity(): void
    {
        config(['services.inspector.regions' => ['ap-southeast-2']]);
        $client = \Mockery::mock(Inspector2Client::class);
        $client->shouldReceive('listFindings')->once()->with([
            'filterCriteria' => [
                'findingStatus' => [['comparison' => 'EQUALS', 'value' => 'ACTIVE']],
                'findingType' => [
                    ['comparison' => 'EQUALS', 'value' => 'PACKAGE_VULNERABILITY'],
                    ['comparison' => 'EQUALS', 'value' => 'CODE_VULNERABILITY'],
                ],
            ],
            'maxResults' => 100,
        ])->andReturn([
            'findings' => [[
                'title' => 'Critical operating system vulnerability',
                'type' => 'PACKAGE_VULNERABILITY',
                'severity' => 'CRITICAL',
                'description' => 'A remote attacker may execute arbitrary code in the affected package.',
                'remediation' => ['recommendation' => ['text' => 'Upgrade the installed package to the fixed version.', 'Url' => 'https://example.test/security/advisory']],
                'inspectorScore' => 9.6,
                'firstObservedAt' => now()->subDays(41)->toIso8601String(),
                'exploitAvailable' => 'YES',
                'fixAvailable' => 'YES',
                'resources' => [[
                    'id' => 'i-0123456789abcdef0',
                    'type' => 'AWS_EC2_INSTANCE',
                    'region' => 'ap-southeast-2',
                    'providerAccountId' => '123456789012',
                    'tags' => ['Name' => 'Production VM'],
                    'details' => ['awsEc2Instance' => ['platform' => 'UBUNTU_24_04', 'imageId' => 'ami-test-image']],
                ]],
                'packageVulnerabilityDetails' => [
                    'vulnerabilityId' => 'CVE-2026-1234',
                    'source' => 'NVD',
                    'cvss' => [['baseScore' => 9.8, 'version' => '3.1', 'source' => 'NVD', 'scoringVector' => 'CVSS:3.1/AV:N/AC:L']],
                    'vulnerablePackages' => [['name' => 'openssl', 'fixedInVersion' => '3.0.15']],
                ],
            ], [
                'title' => 'High package vulnerability',
                'type' => 'PACKAGE_VULNERABILITY',
                'severity' => 'HIGH',
                'exploitAvailable' => 'NO',
                'fixAvailable' => 'YES',
                'resources' => [[
                    'id' => 'i-0123456789abcdef0',
                    'type' => 'AWS_EC2_INSTANCE',
                    'region' => 'ap-southeast-2',
                    'providerAccountId' => '123456789012',
                    'tags' => ['Name' => 'Production VM'],
                    'details' => ['awsEc2Instance' => ['platform' => 'UBUNTU_24_04', 'imageId' => 'ami-test-image']],
                ]],
                'packageVulnerabilityDetails' => [
                    'vulnerabilityId' => 'CVE-2026-5678',
                    'vulnerablePackages' => [['name' => 'curl', 'fixedInVersion' => '8.9.0']],
                ],
            ], [
                'title' => 'Code injection vulnerability',
                'type' => 'CODE_VULNERABILITY',
                'severity' => 'HIGH',
                'resources' => [['id' => 'repo/main.php', 'type' => 'AWS_CODE_REPOSITORY', 'region' => 'ap-southeast-2']],
                'codeVulnerabilityDetails' => [
                    'detectorName' => 'SQL injection',
                    'filePath' => 'src/main.php',
                    'cwes' => ['CWE-89'],
                ],
            ]],
        ]);

        $report = (new AwsInspectorReport($client))->build();

        $this->assertTrue($report['available']);
        $this->assertSame(3, $report['total']);
        $this->assertSame(1, $report['counts']['CRITICAL']);
        $this->assertSame(2, $report['counts']['HIGH']);
        $this->assertSame('CVE-2026-1234', $report['findings'][0]['vulnerability_id']);
        $this->assertSame('openssl', $report['findings'][0]['packages']);
        $this->assertSame('3.0.15', $report['findings'][0]['fixed_versions']);
        $this->assertSame('YES', $report['findings'][0]['exploit_available']);
        $this->assertSame('YES', $report['findings'][0]['fix_available']);
        $this->assertSame(41, $report['findings'][0]['age_days']);
        $this->assertTrue($report['findings'][0]['first_observed_at']->isSameDay(now()->subDays(41)));
        $this->assertSame('A remote attacker may execute arbitrary code in the affected package.', $report['findings'][0]['description']);
        $this->assertSame('Upgrade the installed package to the fixed version.', $report['findings'][0]['remediation']);
        $this->assertSame(9.6, $report['findings'][0]['inspector_score']);
        $this->assertSame(9.8, $report['findings'][0]['cvss'][0]['score']);
        $this->assertSame(2, $report['package_coverage']['total']);
        $this->assertSame(1, $report['package_coverage']['exploit']['YES']);
        $this->assertSame(2, $report['package_coverage']['fix']['YES']);
        $this->assertSame('Production VM', $report['fix_available_machines'][0]['name']);
        $this->assertSame('UBUNTU_24_04', $report['fix_available_machines'][0]['operating_system']);
        $this->assertSame('ami-test-image', $report['fix_available_machines'][0]['image_id']);
        $this->assertSame(1, $report['fix_available_machines'][0]['critical']);
        $this->assertSame(1, $report['fix_available_machines'][0]['high']);
        $this->assertSame(2, $report['fix_available_machines'][0]['all']);
        $this->assertSame(['ap-southeast-2|123456789012|i-0123456789abcdef0'], $report['findings'][0]['machine_keys']);
        $this->assertSame('ap-southeast-2|123456789012|i-0123456789abcdef0', $report['fix_available_machines'][0]['key']);
        $this->assertSame('CWE-89', $report['findings'][2]['vulnerability_id']);
        $this->assertSame('SQL injection src/main.php', $report['findings'][2]['packages']);
    }

    public function test_inspector_coverage_tiles_show_reported_yes_partial_and_missing_counts(): void
    {
        $inspectorReport = \Mockery::mock(AwsInspectorReport::class);
        $inspectorReport->shouldReceive('build')->once()->andReturn([
            'available' => true,
            'partial' => false,
            'regions' => ['ap-southeast-2'],
            'failed_regions' => [],
            'findings' => [
                [
                    'severity' => 'CRITICAL',
                    'title' => 'Critical test finding',
                    'vulnerability_id' => 'CVE-TEST-1',
                    'resource' => 'i-test',
                    'resource_type' => 'AWS_EC2_INSTANCE',
                    'packages' => 'openssl',
                    'fix_available' => 'YES',
                    'fixed_versions' => '3.0.15',
                    'exploit_available' => 'YES',
                    'region' => 'ap-southeast-2',
                    'machine_keys' => ['ap-southeast-2|123456789012|i-test'],
                    'age_days' => 41,
                    'first_observed_at' => now()->subDays(41),
                ],
                [
                    'severity' => 'HIGH',
                    'title' => 'High test finding',
                    'vulnerability_id' => 'CVE-TEST-2',
                    'resource' => 'i-test-2',
                    'resource_type' => 'AWS_EC2_INSTANCE',
                    'packages' => 'curl',
                    'fix_available' => 'NO',
                    'fixed_versions' => '',
                    'exploit_available' => 'NO',
                    'region' => 'ap-southeast-2',
                    'machine_keys' => ['ap-southeast-2|123456789012|i-test-2'],
                    'age_days' => 5,
                    'first_observed_at' => now()->subDays(5),
                ],
            ],
            'fix_available_machines' => [[
                'key' => 'ap-southeast-2|123456789012|i-test',
                'id' => 'i-test',
                'name' => 'Searchable VM',
                'operating_system' => 'UBUNTU_24_04',
                'image_id' => 'ami-test',
                'account' => '123456789012',
                'cloud_provider' => 'AWS',
                'region' => 'ap-southeast-2',
                'critical' => 1,
                'high' => 0,
                'all' => 1,
            ]],
            'counts' => array_fill_keys(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFORMATIONAL', 'UNTRIAGED'], 0),
            'package_coverage' => [
                'total' => 454,
                'exploit' => ['YES' => 112, 'NO' => 330, 'PARTIAL' => 7, 'NOT_REPORTED' => 5],
                'fix' => ['YES' => 408, 'NO' => 38, 'PARTIAL' => 3, 'NOT_REPORTED' => 5],
            ],
            'total' => 454,
            'refreshed_at' => now(),
            'data_note' => 'Live Inspector test data.',
        ]);
        $this->app->instance(AwsInspectorReport::class, $inspectorReport);
        $settings = MonthlyReportSetting::current();

        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertSee('Findings with public exploit available')
            ->assertSee('112')
            ->assertSee('/ 454 total package findings')
            ->assertSee('7 partial; 5 not reported by Inspector')
            ->assertSee('Findings with fix available')
            ->assertSee('408')
            ->assertSee('3 partial; 5 not reported by Inspector')
            ->assertSee('data-open-inspector-machines', false)
            ->assertSee('inspector-fix-machines-dialog')
            ->assertSee('Filter virtual machines')
            ->assertSee('Searchable VM')
            ->assertSee('Operating system')
            ->assertSee('Machine image')
            ->assertSee('data-inspector-machine-select', false)
            ->assertSee('data-machine-key="ap-southeast-2|123456789012|i-test"', false)
            ->assertSee('data-machine-keys="ap-southeast-2|123456789012|i-test"', false)
            ->assertSee('data-inspector-finding-index="0"', false)
            ->assertSee('role="button" aria-haspopup="dialog"', false)
            ->assertSee('id="inspector-finding-dialog"', false)
            ->assertSee('Finding description')
            ->assertSee('Vulnerable packages')
            ->assertSee('CVSS scores')
            ->assertSee('inspectorFindings', false)
            ->assertSee('Age')
            ->assertSee('First observed at')
            ->assertSee('41 days')
            ->assertSee('<th>Fix available</th><th>Age</th><th>First observed at</th></tr>', false)
            ->assertSee('inspector-machine-filter', false)
            ->assertSee('data-inspector-severity="CRITICAL"', false)
            ->assertSee('data-inspector-severity="HIGH"', false)
            ->assertSee('data-severity="CRITICAL"', false)
            ->assertSee('data-severity="HIGH"', false)
            ->assertSee('data-inspector-filter-empty', false);
    }

    public function test_admin_can_change_the_manager_email_and_rotate_the_report_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);

        $this->get(route('monthly-reports.settings'))->assertOk()->assertSee('Reporting Settings');
        $this->put(route('monthly-reports.settings.update'), ['manager_email' => 'manager@example.com'])
            ->assertSessionHasNoErrors();

        $settings = MonthlyReportSetting::firstOrFail();
        $this->assertSame('manager@example.com', $settings->manager_email);
        $previousToken = $settings->public_token;
        $this->post(route('monthly-reports.settings.rotate'))->assertRedirect();
        $settings->refresh();
        $this->assertNotSame($previousToken, $settings->public_token);
        $this->get(route('monthly-reports.public', ['token' => $previousToken]))->assertNotFound();
    }

    public function test_viewer_cannot_edit_report_delivery_settings(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $viewer->id])->actingAs($viewer);

        $this->get(route('monthly-reports.settings'))->assertForbidden();
    }

    public function test_report_covers_the_previous_complete_calendar_month(): void
    {
        $period = app(MonthlyItSecurityReport::class)->period(CarbonImmutable::parse('2026-10-12 13:00:00'));

        $this->assertSame('September 2026', $period['label']);
        $this->assertSame('2026-09-01', $period['start']->toDateString());
        $this->assertSame('2026-10-01', $period['end']->toDateString());
    }

    public function test_public_report_paginates_service_costs_and_limits_the_chart_legend_to_five(): void
    {
        $settings = MonthlyReportSetting::current();
        $costReport = \Mockery::mock(AwsCostReport::class);
        $costReport->shouldReceive('build')->once()->andReturn([
            'available' => true,
            'labels' => ['Apr 2026', 'May 2026'],
            'services' => collect(range(1, 7))->map(fn ($number) => [
                'name' => $number === 1 ? 'EC2 - Compute' : 'Service '.$number,
                'data' => [120.5 / $number, 98.25 / $number],
                'color' => '#5279df',
            ])->all(),
            'total' => 218.75,
            'unit' => 'USD',
            'range_label' => 'Last 6 months + current month to date',
            'refreshed_at' => now(),
            'data_note' => 'Fetched live. AWS billing data is not real-time and may be delayed.',
        ]);
        $this->app->instance(AwsCostReport::class, $costReport);

        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertSee('aws-cost-chart')
            ->assertSee('Total cost: 218.75 USD')
            ->assertSee('Grouped by AWS service')
            ->assertSee('EC2 - Compute')
            ->assertSee('data-page-size="5"', false)
            ->assertSee('AWS service cost table pagination')
            ->assertSee('legendItem.index < 5', false)
            ->assertSee('Refresh costs')
            ->assertSee('not real-time');
    }

    public function test_ticket_created_this_month_appears_in_current_month_change_log(): void
    {
        $today = CarbonImmutable::now();
        $settings = MonthlyReportSetting::current();
        $ticket = SupportTicket::create([
            'title' => 'Production change ticket',
            'issue' => 'Production configuration was updated.',
            'reported_by' => 'Production operator',
            'status' => 'open',
        ]);
        SupportTicketChange::create([
            'support_ticket_id' => $ticket->id,
            'actor_id' => null,
            'action' => 'created',
            'changes' => ['title' => ['before' => null, 'after' => $ticket->title]],
        ]);

        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertSee($today->subMonth()->format('F Y').' changes')
            ->assertSee($today->format('F Y').' so far')
            ->assertSee('Production change ticket');
    }

    public function test_recent_wordpress_history_shows_only_installed_version_upgrades(): void
    {
        $settings = MonthlyReportSetting::current();
        $site = WordpressSite::forceCreate([
            'name' => 'Production WordPress',
            'slug' => 'production-wordpress',
            'url' => 'https://example.com',
            'monitor_token' => hash('sha256', 'wp-report-test-token'),
            'is_active' => true,
        ]);
        WordpressPluginUpdate::create([
            'wordpress_site_id' => $site->id,
            'site_name' => $site->name,
            'plugin_name' => 'Cache Optimizer',
            'current_version' => '1.2.0',
            'latest_version' => '1.3.0',
            'status' => 'outdated',
            'checked_at' => now()->subDays(31),
        ]);
        WordpressCoreUpdate::create([
            'wordpress_site_id' => $site->id,
            'site_name' => $site->name,
            'current_version' => '6.7.1',
            'latest_version' => '6.7.2',
            'status' => 'outdated',
            'checked_at' => now()->subDays(31),
        ]);

        $observedAt = now()->subDay()->startOfMinute();
        $plugin = [
            'wordpress_site_id' => $site->id,
            'site_name' => 'Old display name',
            'plugin_name' => 'Cache Optimizer',
            'current_version' => '1.3.0',
            'latest_version' => '1.4.0',
            'status' => 'outdated',
        ];
        WordpressPluginUpdate::create($plugin + ['checked_at' => $observedAt]);
        WordpressPluginUpdate::create($plugin + ['checked_at' => now()]);
        WordpressCoreUpdate::create([
            'wordpress_site_id' => $site->id,
            'site_name' => $site->name,
            'current_version' => '6.7.2',
            'latest_version' => '6.7.2',
            'status' => 'up_to_date',
            'checked_at' => $observedAt,
        ]);
        WordpressPluginUpdate::create(array_replace($plugin, [
            'plugin_name' => 'Never upgraded plugin',
            'checked_at' => now(),
        ]));
        WordpressPluginUpdate::create(array_replace($plugin, [
            'current_version' => '1.2.0',
            'checked_at' => now()->addSecond(),
        ]));

        $history = app(MonthlyItSecurityReport::class)->build()['wordpress_update_history'];
        $this->assertCount(2, $history);
        foreach ($history as $event) {
            $this->assertTrue($event['at']->equalTo($observedAt));
            $this->assertSame($site->name, $event['site']);
        }
        $this->assertSame('1.2.0', $history[0]['current']);
        $this->assertSame('1.3.0', $history[0]['latest']);

        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertSee('Recent WordPress update history · last 30 days')
            ->assertSee('Update observed at')
            ->assertSee($observedAt->format('j M Y, H:i:s T'))
            ->assertSee('Installed version increased from 1.2.0 to 1.3.0.')
            ->assertSee('Cache Optimizer')
            ->assertSee('1.2.0 → 1.3.0')
            ->assertSee('WordPress core')
            ->assertSee('6.7.1 → 6.7.2');
    }

    public function test_recent_history_tables_have_independent_five_row_pagination_controls(): void
    {
        $settings = MonthlyReportSetting::current();
        $site = WordpressSite::forceCreate([
            'name' => 'Pagination Test Site',
            'slug' => 'pagination-test-site',
            'url' => 'https://pagination.example.com',
            'monitor_token' => hash('sha256', 'pagination-test-token'),
            'is_active' => true,
        ]);

        foreach (range(1, 6) as $number) {
            Ec2PatchStatus::create([
                'instance_id' => sprintf('i-%08x', $number),
                'aws_region' => 'ap-southeast-2',
                'instance_name' => 'History Server '.$number,
                'missing_count' => 1,
                'security_count' => 0,
                'installed_count' => $number - 1,
                'failed_count' => 0,
                'reboot_required' => false,
                'checked_at' => now()->subDays(31),
            ]);
            Ec2PatchStatus::create([
                'instance_id' => sprintf('i-%08x', $number),
                'aws_region' => 'ap-southeast-2',
                'instance_name' => 'History Server '.$number,
                'missing_count' => 0,
                'security_count' => 0,
                'installed_count' => $number,
                'failed_count' => 0,
                'reboot_required' => false,
                'checked_at' => now()->subMinutes($number),
            ]);
            WordpressPluginUpdate::create([
                'wordpress_site_id' => $site->id,
                'site_name' => $site->name,
                'plugin_name' => 'History Plugin '.$number,
                'current_version' => '0.9.0',
                'latest_version' => '1.0.'.$number,
                'status' => 'outdated',
                'checked_at' => now()->subDays(31),
            ]);
            WordpressPluginUpdate::create([
                'wordpress_site_id' => $site->id,
                'site_name' => $site->name,
                'plugin_name' => 'History Plugin '.$number,
                'current_version' => '1.0.'.$number,
                'latest_version' => '1.0.'.$number,
                'status' => 'up_to_date',
                'checked_at' => now()->subMinutes($number),
            ]);
        }

        $response = $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))->assertOk();
        $content = $response->getContent();

        $this->assertSame(2, substr_count($content, 'data-page-size="5"'));
        $this->assertSame(2, substr_count($content, 'class="report-pagination" data-pagination-controls'));
        $this->assertStringContainsString('Next server patch history page', $content);
        $this->assertStringContainsString('Next WordPress update history page', $content);
        $this->assertStringContainsString('History Server 6', $content);
        $this->assertStringContainsString('History Plugin 6', $content);
    }

    public function test_monthly_command_emails_report_link_to_configured_manager(): void
    {
        Mail::fake();
        $settings = MonthlyReportSetting::current();
        $settings->update(['manager_email' => 'manager@example.com']);

        $this->artisan('reports:send-monthly')->assertSuccessful();

        Mail::assertSent(MonthlyItSecurityReportMail::class, function ($mail) use ($settings) {
            return $mail->hasTo('manager@example.com')
                && str_contains($mail->reportUrl, $settings->public_token)
                && $mail->periodLabel !== '';
        });
        $this->assertNotNull($settings->fresh()->last_sent_at);
    }
}