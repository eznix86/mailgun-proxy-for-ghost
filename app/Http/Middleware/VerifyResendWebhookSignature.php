<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Resend;
use Resend\Exceptions\WebhookSignatureVerificationException;
use Symfony\Component\HttpFoundation\Response;

class VerifyResendWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.resend.webhook_secret');

        abort_if(! is_string($secret) || blank($secret), Response::HTTP_SERVICE_UNAVAILABLE, 'Resend webhook secret is not configured.');

        /** @var string $key */
        $key = config('services.resend.key');

        try {
            Resend::client($key)->webhooks->verify(
                $request->getContent(),
                $this->signatureHeaders($request),
                $secret,
            );
        } catch (WebhookSignatureVerificationException) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid webhook signature.');
        }

        return $next($request);
    }

    /**
     * @return array<string, string>
     */
    private function signatureHeaders(Request $request): array
    {
        return collect(['svix-id', 'svix-timestamp', 'svix-signature'])
            ->filter(fn (string $header): bool => $request->headers->has($header))
            ->mapWithKeys(fn (string $header): array => [$header => (string) $request->headers->get($header)])
            ->all();
    }
}
