<?php

namespace Tests\Feature;

use App\Models\MonthlyReportSetting;
use App\Models\User;
use App\Console\Commands\SendMonthlyItSecurityReport;
use App\Mail\MonthlyItSecurityReportMail;
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
            ->assertSee('AWS costs and backup policy')
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