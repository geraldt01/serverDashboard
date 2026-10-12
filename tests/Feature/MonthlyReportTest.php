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
use App\Services\MonthlyItSecurityReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_report_requires_the_random_public_token(): void
    {
        $settings = MonthlyReportSetting::current();

        $this->get(route('monthly-reports.public', ['token' => str_repeat('a', 64)]))->assertNotFound();
        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('IT &amp; Security Report', false)
            ->assertSee('AWS cost breakdown')
            ->assertSee('Backup policy')
            ->assertSee('Systems, access and operating guide');
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

    public function test_public_report_renders_the_regional_cost_chart_when_cost_data_is_available(): void
    {
        $settings = MonthlyReportSetting::current();
        $costReport = \Mockery::mock(AwsCostReport::class);
        $costReport->shouldReceive('build')->once()->andReturn([
            'available' => true,
            'labels' => ['Apr 2026', 'May 2026'],
            'regions' => [['name' => 'ap-southeast-2', 'data' => [120.5, 98.25], 'color' => '#5279df']],
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
            ->assertSee('ap-southeast-2')
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

    public function test_recent_wordpress_plugin_and_core_reports_appear_in_patch_history(): void
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
            'checked_at' => now(),
        ]);
        WordpressCoreUpdate::create([
            'wordpress_site_id' => $site->id,
            'site_name' => $site->name,
            'current_version' => '6.7.1',
            'latest_version' => '6.7.2',
            'status' => 'outdated',
            'checked_at' => now(),
        ]);

        $this->get(route('monthly-reports.public', ['token' => $settings->public_token]))
            ->assertOk()
            ->assertSee('Recent WordPress update history · last 30 days')
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