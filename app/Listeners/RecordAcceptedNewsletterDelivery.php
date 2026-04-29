<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Newsletter\RecordDeliveryEvent;
use App\Models\NewsletterRequestDelivery;
use Illuminate\Mail\Events\MessageSent;

class RecordAcceptedNewsletterDelivery
{
    public function __construct(private readonly RecordDeliveryEvent $recordDeliveryEvent)
    {
    }

    public function handle(MessageSent $event): void
    {
        $deliveryId = $event->message->getHeaders()->get('X-Newsletter-Delivery-Id')?->getBodyAsString();

        if ($deliveryId === null || $deliveryId === '') {
            return;
        }

        $delivery = NewsletterRequestDelivery::query()->find($deliveryId);

        if ($delivery === null) {
            return;
        }

        $providerMessageId = $event->message->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString();

        $this->recordDeliveryEvent->handle(
            delivery: $delivery,
            event: 'accepted',
            providerEvent: 'message.sent',
            providerEventId: $providerMessageId !== null && $providerMessageId !== '' ? 'message.sent:'.$providerMessageId : null,
            payload: array_filter([
                'provider_message_id' => $providerMessageId,
            ], fn (mixed $value): bool => $value !== null),
        );
    }
}
