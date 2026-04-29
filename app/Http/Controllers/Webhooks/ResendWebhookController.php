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

    public function __construct(private readonly RecordDeliveryEvent $recordDeliveryEvent)
    {
    }

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
                'payload' => $payload,
            ],
        );

        return $this->ok();
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
