<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformCompanyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServerAgentController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\UserController;
use App\Support\ApiException;
use Illuminate\Support\Facades\Route;

// Public: login is rate limited per client address, logout only clears the caller's own token.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/logout', [AuthController::class, 'logout']);

// Agents authenticate with their own secret and can only touch their own commands.
Route::middleware(['agent', 'throttle:agent'])->prefix('agent')->group(function () {
    Route::post('/poll', [AgentController::class, 'poll']);
    Route::post('/commands/{id}/result', [AgentController::class, 'result'])->whereNumber('id');
});

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/change-password', [AuthController::class, 'changePassword']);

    Route::middleware('permission:users:manage')->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::patch('/{id}', [UserController::class, 'update'])->whereNumber('id');
    });

    Route::prefix('servers')->group(function () {
        Route::get('/', [ServerController::class, 'index'])->middleware('permission:servers:read');
        Route::get('/{id}', [ServerController::class, 'show'])->middleware('permission:servers:read')->whereNumber('id');
        Route::post('/', [ServerController::class, 'store'])->middleware('permission:servers:write');
        Route::patch('/{id}', [ServerController::class, 'update'])->middleware('permission:servers:write')->whereNumber('id');
        Route::delete('/{id}', [ServerController::class, 'destroy'])->middleware('permission:servers:write')->whereNumber('id');
        Route::put('/{id}/assignments', [ServerController::class, 'assign'])->middleware('permission:servers:write')->whereNumber('id');
    });

    Route::get('/servers/{id}/agent', [ServerAgentController::class, 'show'])->middleware('permission:servers:read')->whereNumber('id');
    Route::post('/servers/{id}/agent', [ServerAgentController::class, 'enroll'])->middleware('permission:servers:write')->whereNumber('id');
    Route::delete('/servers/{id}/agent', [ServerAgentController::class, 'destroy'])->middleware('permission:servers:write')->whereNumber('id');
    // Backup locations for BMS-managed backups: everyone who can see jobs can see them, admins manage them.
    Route::get('/backup-locations', [LocationController::class, 'index'])->middleware('permission:jobs:read');
    Route::post('/backup-locations', [LocationController::class, 'store'])->middleware('permission:locations:manage');
    Route::patch('/backup-locations/{id}', [LocationController::class, 'update'])->middleware('permission:locations:manage')->whereNumber('id');
    Route::delete('/backup-locations/{id}', [LocationController::class, 'destroy'])->middleware('permission:locations:manage')->whereNumber('id');

    // External backup platforms: connections are admin-only, a server's links follow server permissions.
    Route::middleware('permission:integrations:manage')->prefix('integrations')->group(function () {
        Route::get('/', [IntegrationController::class, 'index']);
        Route::post('/', [IntegrationController::class, 'store']);
        Route::patch('/{id}', [IntegrationController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [IntegrationController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/test', [IntegrationController::class, 'test'])->whereNumber('id');
        Route::post('/{id}/sync', [IntegrationController::class, 'sync'])->whereNumber('id');
        Route::get('/{id}/devices', [IntegrationController::class, 'devices'])->whereNumber('id');
        Route::post('/{id}/import', [IntegrationController::class, 'import'])->whereNumber('id');
    });
    Route::get('/servers/{id}/links', [IntegrationController::class, 'links'])->middleware('permission:servers:read')->whereNumber('id');
    Route::post('/servers/{id}/links', [IntegrationController::class, 'link'])->middleware('permission:servers:write')->whereNumber('id');
    Route::delete('/servers/{id}/links/{linkId}', [IntegrationController::class, 'unlink'])->middleware('permission:servers:write')->whereNumber('id')->whereNumber('linkId');
    Route::get('/agents', [AgentsController::class, 'index'])->middleware('permission:servers:read');
    Route::get('/agent-commands', [ServerAgentController::class, 'commands'])->middleware('permission:jobs:read');

    Route::prefix('backup-jobs')->group(function () {
        Route::post('/{id}/run', [ServerAgentController::class, 'runBackup'])->middleware('permission:backups:run')->whereNumber('id');
        Route::get('/', [JobController::class, 'index'])->middleware('permission:jobs:read');
        Route::get('/{id}', [JobController::class, 'show'])->middleware('permission:jobs:read')->whereNumber('id');
        Route::post('/', [JobController::class, 'store'])->middleware('permission:jobs:write');
        Route::patch('/{id}', [JobController::class, 'update'])->middleware('permission:jobs:write')->whereNumber('id');
        Route::delete('/{id}', [JobController::class, 'destroy'])->middleware('permission:jobs:write')->whereNumber('id');
        Route::post('/{id}/test', [JobController::class, 'queueTest'])->middleware('permission:tests:run')->whereNumber('id');
    });

    Route::get('/tests', [TestController::class, 'index'])->middleware('permission:tests:read');
    Route::get('/tests/{id}', [TestController::class, 'show'])->middleware('permission:tests:read')->whereNumber('id');
    Route::get('/test-failures', [TestController::class, 'failureIndex'])->middleware('permission:tests:read');
    Route::post('/test-failures/{id}/resolve', [TestController::class, 'resolve'])->middleware('permission:failures:resolve')->whereNumber('id');

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'readAll']);
        Route::post('/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
    });

    Route::get('/audit', [AuditController::class, 'index'])->middleware('permission:audit:read');

    Route::prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->middleware('permission:reports:read');
        Route::post('/', [ReportController::class, 'store'])->middleware('permission:reports:generate');
        Route::get('/{id}', [ReportController::class, 'show'])->middleware('permission:reports:read')->whereNumber('id');
        Route::get('/{id}/view', [ReportController::class, 'view'])->middleware('permission:reports:read')->whereNumber('id');
    });

    Route::get('/dashboard', [DashboardController::class, 'show'])->middleware('permission:dashboard:read');
    Route::get('/companies/{id}/dashboard', [DashboardController::class, 'forCompany'])->middleware('permission:dashboard:read')->whereNumber('id');

    // Platform admins only: the one place that works across companies.
    Route::middleware('platform')->prefix('platform')->group(function () {
        Route::get('/companies', [PlatformCompanyController::class, 'index']);
        Route::post('/companies', [PlatformCompanyController::class, 'store']);
        Route::post('/companies/{id}/enter', [PlatformCompanyController::class, 'enter'])->whereNumber('id');
    });

    // Inside the authenticated group on purpose: an unauthenticated caller gets 401 for any path and so
    // cannot probe which routes exist.
    Route::any('/{any}', fn () => throw ApiException::notFound('Route', 'ROUTE_001'))->where('any', '.*');
});
