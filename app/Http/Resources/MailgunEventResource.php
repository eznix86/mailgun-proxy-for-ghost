<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NewsletterRequestDeliveryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MailgunEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NewsletterRequestDeliveryEvent $event */
        $event = $this->resource;
        $delivery = $event->delivery;

        return array_filter([
            'id' => (string) $event->id,
            'event' => $event->event,
            'timestamp' => $event->occurred_at?->utc()->getTimestamp(),
            'severity' => $event->severity,
            'recipient' => $delivery->recipient,
            'message' => [
                'headers' => [
                    'message-id' => $delivery->mailgun_message_id,
                    'from' => $delivery->from,
                    'to' => $delivery->recipient,
                    'subject' => $delivery->subject,
                ],
            ],
            'tags' => $delivery->tags ?? [],
            'user-variables' => $this->mailgunUserVariables($delivery->user_variables ?? []),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function mailgunUserVariables(array $variables): array
    {
        return collect($variables)
            ->mapWithKeys(fn (mixed $value, string $key): array => [str_replace('_', '-', $key) => $value])
            ->all();
    }
}
