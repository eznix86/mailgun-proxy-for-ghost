<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMailgunBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.mailgun.key');

        abort_if(
            ! is_string($expected) || blank($expected),
            Response::HTTP_SERVICE_UNAVAILABLE,
            'Mailgun proxy not configured.',
        );

        if ($request->getUser() !== 'api'
            || ! hash_equals($expected, (string) $request->getPassword())) {
            return response()->json([], Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Mailgun Proxy"',
            ]);
        }

        return $next($request);
    }
}
