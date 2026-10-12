<?php

use App\Support\AgentRelease;
use Illuminate\Support\Facades\Route;

// The agent program, so a server can download it and install itself. It holds no secrets (the secret is entered at
// install time), so it is public. Its SHA-256 is shown on the Agents page for anyone who wants to check the download.
Route::get(AgentRelease::URL_PATH, function () {
    abort_unless(AgentRelease::exists(), 404);

    return response()->file(AgentRelease::path(), [
        'Content-Type' => 'text/x-python; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="bms_agent.py"',
        'X-Content-SHA256' => AgentRelease::sha256(),
        'Cache-Control' => 'no-cache',
    ]);
})->middleware('throttle:60,1');

// Single-page app: any non-API path serves the UI shell. Real files in public/ (app.js, styles.css) are
// served directly by the web server before Laravel sees the request.
Route::get('/{path?}', fn () => response()->file(public_path('index.html')))->where('path', '^(?!api/).*$');
