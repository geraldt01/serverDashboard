<?php

namespace App\Http\Controllers;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticationController extends Controller
{
    public function show(Request $request, Google2FA $google2fa)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return $this->cancel($request);
        }

        $secret = null;
        $qrCode = null;
        if (! $user->two_factor_confirmed_at) {
            $secret = $request->session()->get('mfa.secret');
            if (! $secret) {
                $secret = $google2fa->generateSecretKey(32);
                $request->session()->put('mfa.secret', $secret);
            }
            $uri = $google2fa->getQRCodeUrl('ServerDashboard', $user->email, $secret);
            $writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd));
            $qrCode = 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($uri));
        }

        return response()->view('auth.authenticator', compact('secret', 'qrCode'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, Google2FA $google2fa)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return $this->cancel($request);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $limiterKey = 'mfa:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($limiterKey).' seconds.']);
        }
        RateLimiter::hit($limiterKey, 60);

        $enrolling = ! $user->two_factor_confirmed_at;
        $secret = $enrolling ? $request->session()->get('mfa.secret') : $user->two_factor_secret;
        if (! $secret) {
            return $this->cancel($request);
        }

        $recoveryCodes = [];
        $verified = DB::transaction(function () use ($user, $secret, $enrolling, $data, $google2fa, &$recoveryCodes) {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);
            if (! $lockedUser || $lockedUser->password !== $user->password ||
                (bool) $lockedUser->two_factor_confirmed_at !== ! $enrolling ||
                (! $enrolling && $lockedUser->two_factor_secret !== $secret)) {
                return false;
            }

            $code = trim($data['code']);
            $timestamp = preg_match('/\A[0-9]{6}\z/', $code)
                ? $google2fa->verifyKeyNewer($secret, $code, $lockedUser->two_factor_last_used_at ?? 0, 1)
                : false;

            if ($timestamp !== false) {
                $lockedUser->forceFill(['two_factor_last_used_at' => $timestamp]);
                if ($enrolling) {
                    $recoveryCodes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 8));
                    $lockedUser->forceFill([
                        'two_factor_secret' => $secret,
                        'two_factor_confirmed_at' => now(),
                        'two_factor_recovery_codes' => array_map(fn ($recoveryCode) => hash('sha256', $recoveryCode), $recoveryCodes),
                        'remember_token' => null,
                    ]);
                }
                $lockedUser->save();

                return true;
            }

            if (! $enrolling) {
                $hashes = $lockedUser->two_factor_recovery_codes ?? [];
                foreach ($hashes as $index => $hash) {
                    if (hash_equals($hash, hash('sha256', $code))) {
                        unset($hashes[$index]);
                        $lockedUser->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                        return true;
                    }
                }
            }

            return false;
        });

        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'The code is invalid or has already been used.']);
        }

        RateLimiter::clear($limiterKey);
        Auth::login($user->fresh(), false);
        $request->session()->regenerate();
        $request->session()->forget(['mfa.pending', 'mfa.secret']);
        $request->session()->put('mfa.verified_user', $user->id);

        if ($enrolling) {
            $request->session()->flash('mfa.recovery_codes', $recoveryCodes);

            return redirect()->route('mfa.recovery-codes');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function recoveryCodes(Request $request)
    {
        $codes = $request->session()->get('mfa.recovery_codes');
        if (! $codes) {
            return redirect()->intended(route('dashboard'));
        }

        return response()->view('auth.recovery-codes', compact('codes'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function cancel(Request $request)
    {
        $request->session()->forget(['mfa.pending', 'mfa.secret']);
        $request->session()->regenerate();

        return redirect()->route('login');
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('mfa.pending');
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            return null;
        }

        $user = User::find($pending['user_id'] ?? null);

        return $user && hash_equals(hash('sha256', $user->password), $pending['password_hash'] ?? '') ? $user : null;
    }
}
