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
        if ($request->getUser() !== 'api' || $request->getPassword() !== config('services.mailgun.key')) {
            return response()->json([], Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Mailgun Proxy"',
            ]);
        }

        return $next($request);
    }
}
