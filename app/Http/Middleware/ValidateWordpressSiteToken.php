<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ValidateWordpressSiteToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('wordpressSite');
        $timestamp = (string) $request->header('X-WordPress-Monitor-Timestamp');
        $nonce = (string) $request->header('X-WordPress-Monitor-Nonce');
        $signature = (string) $request->header('X-WordPress-Monitor-Signature');

        if (! $site || ! $site->is_active || ! ctype_digit($timestamp) || ! preg_match('/\A[a-f0-9]{32}\z/i', $nonce) || ! preg_match('/\A[a-f0-9]{64}\z/i', $signature)) {
            return response()->json(['message' => 'Invalid WordPress monitoring signature.'], 401);
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Expired WordPress monitoring request.'], 401);
        }

        $payload = $timestamp . '.' . $nonce . '.' . $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $site->monitoringToken());

        if (! hash_equals($expectedSignature, strtolower($signature))) {
            return response()->json(['message' => 'Invalid WordPress monitoring signature.'], 401);
        }

        DB::table('wordpress_report_nonces')->where('seen_at', '<', now()->subDay())->delete();

        try {
            DB::table('wordpress_report_nonces')->insert([
                'wordpress_site_id' => $site->id,
                'nonce' => strtolower($nonce),
                'seen_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['message' => 'Replayed WordPress monitoring request.'], 401);
        }

        return $next($request);
    }
}
