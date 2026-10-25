<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Infra health endpoint: no configuration values or secrets are exposed.
\Illuminate\Support\Facades\Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::select('select 1');
        return response()->json(['status' => 'ok', 'database' => 'ok']);
    } catch (\Throwable $e) {
        return response()->json(['status' => 'degraded', 'database' => 'error'], 503);
    }
});


