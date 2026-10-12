<?php

use App\Http\Middleware\CookieToken;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([RequestId::class, SecurityHeaders::class]);
        $middleware->prependToGroup('api', CookieToken::class);
        $middleware->alias(['permission' => RequirePermission::class, 'tenant' => ResolveTenant::class, 'platform' => RequirePlatformAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected, user-facing errors (validation, 403, 404, ...) are not faults, so they are not logged as errors.
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $body = fn (Request $r, string $error, string $code, array $extra = []) => array_merge(
            ['success' => false, 'error' => $error, 'error_code' => $code], $extra, ['request_id' => $r->attributes->get('request_id')],
        );

        $exceptions->render(fn (ApiException $e, Request $r) => response()->json($body($r, $e->getMessage(), $e->errorCode, $e->extra), $e->status));

        $exceptions->render(function (ValidationException $e, Request $r) use ($body) {
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $details[] = ['field' => $field, 'message' => $message];
                }
            }

            return response()->json($body($r, 'Invalid request', 'VALIDATION_001', ['details' => $details]), 400);
        });

        $exceptions->render(fn (AuthenticationException $e, Request $r) => response()->json($body($r, 'Authentication required', 'AUTH_002'), 401));

        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($body) {
            if ($r->is('api/*')) {
                return response()->json($body($r, 'Route not found', 'ROUTE_001'), 404);
            }
        });

        // Anything unexpected is logged in full and reported without internal detail.
        $exceptions->render(function (Throwable $e, Request $r) use ($body) {
            if ($r->is('api/*') && ! ($e instanceof HttpExceptionInterface) && ! config('app.debug')) {
                report($e);

                return response()->json($body($r, 'Internal server error', 'SERVER_500'), 500);
            }
        });
    })->create();
