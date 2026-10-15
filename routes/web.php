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

// The agent bundled with its own Python, for a Linux machine that has none. Made on request (agent/build_bundle.sh) and placed in
// storage/app/agent-bundles by hand, so this is a 404 until someone does. Like the script, it holds no secrets.
Route::get('/agent/bundle/{arch}', function (string $arch) {
    $file = AgentRelease::bundle($arch);
    abort_unless($file !== null, 404);

    return response()->file($file, [
        'Content-Type' => 'application/gzip',
        'Content-Disposition' => 'attachment; filename="'.basename($file).'"',
        'X-Content-SHA256' => hash_file('sha256', $file),
        'Cache-Control' => 'no-cache',
    ]);
})->where('arch', 'x86_64|aarch64')->middleware('throttle:30,1');

// Single-page app: any non-API path serves the UI shell. Real files in public/ (app.js, styles.css) are
// served directly by the web server before Laravel sees the request.
Route::get('/{path?}', fn () => response()->file(public_path('index.html')))->where('path', '^(?!api/).*$');
