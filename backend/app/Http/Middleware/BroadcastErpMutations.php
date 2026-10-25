<?php

namespace App\Http\Middleware;

use App\Events\ErpDataChanged;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Emits a generic invalidation event for successful authenticated API writes. */
class BroadcastErpMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $method = strtoupper($request->method());

        if (!$request->is('api/v1/*') || !in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $user = $request->user();
        if (!$user) {
            return $response;
        }

        // Exclude authentication/security endpoints from general data invalidation.
        $path = '/' . ltrim($request->path(), '/');
        if (preg_match('#^/api/v1/(login|logout|register|two-factor|2fa|broadcasting/auth)(/|$)#i', $path)) {
            return $response;
        }

        event(new ErpDataChanged(
            path: $path,
            method: $method,
            actorId: (int) $user->getAuthIdentifier(),
            changedAt: now()->toIso8601String(),
            eventId: (string) Str::uuid(),
        ));

        return $response;
    }
}
