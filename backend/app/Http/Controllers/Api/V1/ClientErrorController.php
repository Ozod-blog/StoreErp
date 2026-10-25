<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:window_error,unhandled_rejection,react_error'],
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:4000'],
        ]);
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $data['message']);
        $stack = isset($data['stack']) ? preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $data['stack']) : null;
        $context = ['kind' => $data['kind'], 'message' => $message, 'client_stack' => $stack, 'ip_hash' => hash('sha256', (string) $request->ip().(string) config('app.key'))];
        if (class_exists(\Sentry\Laravel\Facades\Sentry::class) && config('services.sentry.dsn')) {
            \Sentry\Laravel\Facades\Sentry::withScope(function ($scope) use ($context) {
                $scope->setExtras($context);
                \Sentry\Laravel\Facades\Sentry::captureException(new \RuntimeException('Frontend: '.$context['kind'].' — '.$context['message']));
            });
        } else {
            Log::warning('Frontend client error', $context);
        }
        return response()->json(['received' => true], 202);
    }
}
