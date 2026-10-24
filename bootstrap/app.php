<?php

use App\Http\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport(ApiError::class);

        $exceptions->render(fn (ApiError $e) => $e->toResponse());

        $exceptions->render(fn (ValidationException $e) => response()->json([
            'error' => 'bad_request',
            'message' => $e->validator->errors()->first(),
        ], 400));

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json(['error' => 'internal'], 500);
        });

        $exceptions->shouldRenderJsonWhen(fn () => true);
    })
    ->create();
