<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Newsletter\RecordDeliveryEvent;
use App\Http\Controllers\Controller;
use App\Models\NewsletterRequestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResendWebhookController extends Controller
{
    /**
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const EVENTS = [
        'email.bounced' => ['rejected', null],
        'email.clicked' => ['clicked', null],
        'email.complained' => ['complained', null],
        'email.delivered' => ['delivered', null],
        'email.delivery_delayed' => ['failed', 'temporary'],
        'email.failed' => ['failed', null],
        'email.opened' => ['opened', null],
    ];

    public function __construct(private readonly RecordDeliveryEvent $recordDeliveryEvent) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $providerEvent = (string) data_get($payload, 'type', '');
        $providerMessageId = data_get($payload, 'data.email_id');
        $providerEventId = $request->header('svix-id');
        $event = self::EVENTS[$providerEvent] ?? null;

        if ($event === null) {
            return $this->ok();
        }

        $delivery = $this->resolveDelivery($providerMessageId);

        if ($delivery === null) {
            return $this->ok();
        }

        $this->recordDeliveryEvent->handle(
            delivery: $delivery,
            event: $event[0],
            providerEvent: $providerEvent,
            providerEventId: $providerEventId,
            severity: $event[1],
            occurredAt: CarbonImmutable::parse((string) data_get($payload, 'created_at', now()->toISOString())),
            payload: [
                'provider_message_id' => $providerMessageId,
                'provider_event_id' => $providerEventId,
                'reason' => $this->failureReason($providerEvent, $payload),
                'payload' => $payload,
            ],
        );

        return $this->ok();
    }

    /**
     * Extract the honest failure reason Resend supplied so it can be served to
     * Ghost as `delivery-status.message` (and drive the dashboard's failure
     * grouping via `payload.reason`). Resend carries the reason on
     * `data.bounce.message` for hard bounces and `data.failed.reason` for
     * send failures; anything else has no reason.
     *
     * @param  array<string, mixed>  $payload
     */
    private function failureReason(string $providerEvent, array $payload): ?string
    {
        $reason = match ($providerEvent) {
            'email.bounced' => data_get($payload, 'data.bounce.message'),
            'email.failed' => data_get($payload, 'data.failed.reason'),
            default => null,
        };

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private function resolveDelivery(mixed $providerMessageId): ?NewsletterRequestDelivery
    {
        if (! is_string($providerMessageId) || $providerMessageId === '') {
            return null;
        }

        return NewsletterRequestDelivery::query()
            ->where('provider_message_id', $providerMessageId)
            ->first();
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
