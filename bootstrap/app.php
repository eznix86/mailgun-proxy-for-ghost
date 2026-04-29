<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureMailgunBasicAuth;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyResendWebhookSignature;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/mailgun.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->alias([
            'mailgun.auth' => EnsureMailgunBasicAuth::class,
            'resend.webhook' => VerifyResendWebhookSignature::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $throwable): bool {
            if ($request->is('v3/*')) {
                return true;
            }

            return $request->expectsJson();
        });

        $exceptions->render(function (Throwable $throwable, Request $request): ?Response {
            if (! $request->is('v3/*')) {
                return null;
            }

            if ($throwable instanceof ValidationException) {
                $message = collect($throwable->errors())
                    ->flatten()
                    ->first() ?? 'A simple message describing the issue.';

                return response()->json([
                    'message' => $message,
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($throwable instanceof AuthenticationException) {
                return response()->json([], Response::HTTP_UNAUTHORIZED);
            }

            if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                return response()->json([
                    'message' => $throwable->getMessage(),
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() >= 500) {
                return response()->json([
                    'message' => 'Internal Server Error',
                ], $throwable->getStatusCode());
            }

            return response()->json([
                'message' => 'Internal Server Error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
