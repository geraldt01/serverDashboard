<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ValidateOtherServerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = $request->route('otherServer');
        $timestamp = (string) $request->header('X-Server-Monitor-Timestamp');
        $nonce = (string) $request->header('X-Server-Monitor-Nonce');
        $signature = (string) $request->header('X-Server-Monitor-Signature');

        if (! $server || ! $server->is_active || ! ctype_digit($timestamp) || ! preg_match('/\A[a-f0-9]{32}\z/i', $nonce) || ! preg_match('/\A[a-f0-9]{64}\z/i', $signature)) {
            return response()->json(['message' => 'Invalid server monitoring signature.'], 401);
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Expired server monitoring request.'], 401);
        }

        $payload = $timestamp . '.' . $nonce . '.' . $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $server->monitoringToken());

        if (! hash_equals($expectedSignature, strtolower($signature))) {
            return response()->json(['message' => 'Invalid server monitoring signature.'], 401);
        }

        DB::table('other_server_report_nonces')->where('seen_at', '<', now()->subDay())->delete();

        try {
            DB::table('other_server_report_nonces')->insert([
                'other_server_id' => $server->id,
                'nonce' => strtolower($nonce),
                'seen_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['message' => 'Replayed server monitoring request.'], 401);
        }

        return $next($request);
    }
}
