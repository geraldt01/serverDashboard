<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateMonitorKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.monitoring.ingest_key');
        $provided = (string) $request->header('X-Monitor-Key');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid monitor key.'], 401);
        }

        return $next($request);
    }
}
