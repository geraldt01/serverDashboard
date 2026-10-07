<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_requires_valid_code_and_shows_recovery_codes_once(): void
    {
        $user = User::factory()->create(['password' => 'Password#123']);
        $this->startLogin($user);
        $this->get(route('mfa.challenge'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $secret = session('mfa.secret');
        $this->post(route('mfa.verify'), ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertGuest();

        $this->post(route('mfa.verify'), ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertRedirect(route('mfa.recovery-codes'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $codes = session('mfa.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertSame(hash('sha256', $codes[0]), $user->fresh()->two_factor_recovery_codes[0]);
        $this->get(route('mfa.recovery-codes'))->assertOk()->assertSee($codes[0]);
        $this->get(route('mfa.recovery-codes'))->assertRedirect(route('dashboard'));
        $this->get('/')->assertOk();
    }

    public function test_enrolled_login_requires_totp_and_rejects_replay(): void
    {
        $user = $this->enrolledUser();
        $this->startLogin($user);
        $this->get(route('mfa.challenge'))->assertOk()->assertSee('Verify your login')->assertDontSee('Manual setup key');
        $code = (new Google2FA)->getCurrentOtp($user->two_factor_secret);
        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->startLogin($user);
        $this->post(route('mfa.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->enrolledUser();
        $recoveryCode = bin2hex(random_bytes(10));
        $user->forceFill(['two_factor_recovery_codes' => [hash('sha256', $recoveryCode)]])->save();
        $this->startLogin($user);
        $this->post(route('mfa.verify'), ['code' => $recoveryCode])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
        $this->post(route('logout'));
        $this->startLogin($user);
        $this->post(route('mfa.verify'), ['code' => $recoveryCode])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_expired_pending_login_and_changed_password_cannot_complete_mfa(): void
    {
        $user = $this->enrolledUser();
        $this->startLogin($user);
        $this->travel(11)->minutes();
        $this->post(route('mfa.verify'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->travelBack();

        $this->startLogin($user);
        $user->forceFill(['password' => 'Changed#123'])->save();
        $this->post(route('mfa.verify'), ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)])
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_account_attempt_limit_survives_restarting_password_login(): void
    {
        $user = $this->enrolledUser();
        $this->startLogin($user);
        foreach (range(1, 5) as $attempt) {
            $this->post(route('mfa.verify'), ['code' => 'invalid'])->assertSessionHasErrors('code');
        }
        $this->post(route('mfa.cancel'))->assertRedirect(route('login'));
        $this->startLogin($user);
        $this->post(route('mfa.verify'), ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_existing_password_only_session_is_rejected(): void
    {
        $user = $this->enrolledUser();
        $this->actingAs($user)->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_missing_pending_login_is_rejected_and_cancel_clears_setup(): void
    {
        $this->get(route('mfa.challenge'))->assertRedirect(route('login'));
        $this->post(route('mfa.verify'), ['code' => '123456'])->assertRedirect(route('login'));
        $user = User::factory()->create(['password' => 'Password#123']);
        $this->startLogin($user);
        $this->get(route('mfa.challenge'))->assertOk();
        $this->post(route('mfa.cancel'))->assertRedirect(route('login'))->assertSessionMissing('mfa.secret')->assertSessionMissing('mfa.pending');
        $this->assertGuest();
    }

    public function test_wrong_password_does_not_start_mfa(): void
    {
        $user = $this->enrolledUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'Incorrect#123'])
            ->assertSessionHasErrors('email')->assertSessionMissing('mfa.pending');
        $this->assertGuest();
    }

    private function enrolledUser(): User
    {
        $user = User::factory()->create(['password' => 'Password#123']);
        $user->forceFill([
            'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function startLogin(User $user): void
    {
        Auth::forgetGuards();
        $this->post('/login', ['email' => $user->email, 'password' => 'Password#123'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
    }

    public function test_password_only_login_cannot_access_protected_routes(): void
    {
        $user = User::factory()->create(['password' => 'Password#123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password#123'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/login/authenticator')->assertOk()->assertSee('Set up authenticator');
    }

    public function test_mfa_secrets_are_encrypted_and_hidden(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => 'test-secret',
            'two_factor_recovery_codes' => ['test-hash'],
        ])->save();

        $this->assertSame('test-secret', $user->fresh()->two_factor_secret);
        $this->assertSame(['test-hash'], $user->fresh()->two_factor_recovery_codes);
        $this->assertNotSame('test-secret', DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }
}
