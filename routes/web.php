<?php

use Illuminate\Support\Facades\Route;

// Single-page app: any non-API path serves the UI shell. Real files in public/ (app.js, styles.css) are
// served directly by the web server before Laravel sees the request.
Route::get('/{path?}', fn () => response()->file(public_path('index.html')))->where('path', '^(?!api/).*$');
