<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'business.context' => \App\Http\Middleware\SetBusinessContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, $request) {
            // Always return JSON for API requests
            if ($request->is('api/*') || $request->expectsJson()) {
                $statusCode = 500;
                
                // Determine status code from exception
                if (method_exists($e, 'getStatusCode')) {
                    $statusCode = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $statusCode = 401;
                } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    $statusCode = 403;
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $statusCode = 404;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                }

                $response = [
                    'message' => $e->getMessage() ?: 'Server Error',
                    'error' => class_basename($e),
                ];

                // Add validation errors for ValidationException
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $response['errors'] = $e->errors();
                }

                // Add debug information in debug mode
                if (config('app.debug')) {
                    $response['debug'] = [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => collect($e->getTrace())->map(fn($trace) => [
                            'file' => $trace['file'] ?? 'unknown',
                            'line' => $trace['line'] ?? 'unknown',
                            'function' => $trace['function'] ?? 'unknown',
                        ])->take(10)->all(),
                    ];
                }

                return response()->json($response, $statusCode);
            }
        });
    })->create();
