<?php

declare(strict_types=1);

namespace App\Actions\Newsletter;

use App\Models\NewsletterRequestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class RecordDeliveryEvent
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function handle(
        NewsletterRequestDelivery $delivery,
        string $event,
        ?string $providerEvent = null,
        ?string $providerEventId = null,
        ?string $severity = null,
        ?CarbonImmutable $occurredAt = null,
        ?array $payload = null,
    ): void {
        $occurredAt ??= CarbonImmutable::now();

        if ($providerEventId !== null && $providerEventId !== '') {
            $existingEvent = $delivery->events()
                ->where('provider_event_id', $providerEventId)
                ->first();

            if ($existingEvent !== null) {
                return;
            }
        }

        $delivery->events()->create([
            'event' => $event,
            'provider_event' => $providerEvent,
            'provider_event_id' => $providerEventId,
            'severity' => $severity,
            'occurred_at' => $occurredAt,
            'payload' => $payload,
        ]);

        $delivery->forceFill([
            'latest_event' => $event,
            'latest_severity' => $severity,
            'latest_event_at' => $occurredAt,
            'accepted_at' => $event === 'accepted' ? $occurredAt : $delivery->accepted_at,
            'delivered_at' => $event === 'delivered' ? $occurredAt : $delivery->delivered_at,
            'failed_at' => in_array($event, ['failed', 'rejected'], true) ? $occurredAt : $delivery->failed_at,
            'provider_message_id' => Arr::get($payload, 'provider_message_id', $delivery->provider_message_id),
        ])->save();
    }
}
