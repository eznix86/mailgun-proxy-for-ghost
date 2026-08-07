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

        $ghostEvent = $this->ghostEvent($event->event);

        return array_filter([
            'id' => (string) $event->id,
            'event' => $ghostEvent,
            'timestamp' => $event->occurred_at?->utc()->getTimestamp(),
            'severity' => $this->severity($event, $ghostEvent),
            'recipient' => $delivery->recipient,
            'delivery-status' => $this->deliveryStatus($event, $ghostEvent),
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
     * Translate the proxy's internal event name to the Mailgun event Ghost expects.
     *
     * A Resend hard bounce is stored internally as `rejected` (an established
     * internal state used across the dashboard, health checks and status
     * derivation). Ghost has no `rejected` type — it models a hard bounce as
     * `failed` + `severity=permanent` (spike dossier §A3). This impedance match
     * happens only at the serving boundary; the stored row keeps `rejected`.
     */
    private function ghostEvent(string $storedEvent): string
    {
        return $storedEvent === 'rejected' ? 'failed' : $storedEvent;
    }

    /**
     * Ghost reads `severity` only on `failed` events: `permanent` marks a
     * permanent failure (EmailRecipientFailure + suppression), anything else is
     * treated as temporary (spike dossier §A3). A rejected-sourced row is a hard
     * bounce → `permanent`; a genuine `failed` row defaults to `temporary`.
     */
    private function severity(NewsletterRequestDeliveryEvent $event, string $ghostEvent): ?string
    {
        if ($ghostEvent !== 'failed') {
            return null;
        }

        if ($event->event === 'rejected') {
            return 'permanent';
        }

        return $event->severity ?? 'temporary';
    }

    /**
     * Ghost reads `delivery-status.{message, code}` on failed events to populate
     * the EmailRecipientFailure reason (spike dossier §A3). The message/code are
     * the honest values Resend supplied at ingestion (bounce message / failed
     * reason), normalized onto `payload.reason` — never invented Mailgun codes.
     *
     * @return array<string, mixed>|null
     */
    private function deliveryStatus(NewsletterRequestDeliveryEvent $event, string $ghostEvent): ?array
    {
        if ($ghostEvent !== 'failed') {
            return null;
        }

        $message = data_get($event->payload, 'reason');

        if (! is_string($message) || $message === '') {
            return null;
        }

        $status = ['message' => $message];

        $code = data_get($event->payload, 'code');

        if ($code !== null) {
            $status['code'] = $code;
        }

        return $status;
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
