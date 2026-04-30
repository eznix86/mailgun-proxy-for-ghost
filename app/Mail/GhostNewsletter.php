<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\Newsletter\RecordDeliveryEvent;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;
use App\Models\NewsletterRequestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class GhostNewsletter extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public NewsletterSendRequestData $request,
        public NewsletterRecipientData $recipient,
        public int $deliveryId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->request ? $this->parseAddress($this->request->message->from) : new Address('noreply@example.com', 'Newsletter'),
            replyTo: $this->replyToAddresses(),
            subject: $this->request?->message?->subject ?? 'Newsletter',
        );
    }

    public function headers(): Headers
    {
        $headers = $this->request?->headers ?? [];

        return new Headers(
            text: collect($headers)
                ->merge([
                    'x_newsletter_delivery_id' => (string) $this->deliveryId,
                ])
                ->reject(fn (string $value, string $key) => in_array($key, ['reply_to', 'sender'], true))
                ->mapWithKeys(fn (string $value, string $key) => [$this->headerName($key) => $value])
                ->all(),
        );
    }

    public function content(): Content
    {
        $html = $this->request?->message?->html;
        $text = $this->request?->message?->text;

        return new Content(
            htmlString: $html ?? nl2br(e($text ?? '')),
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return config('services.outbox.provider') === 'resend'
            ? [new RateLimited('resend-outbox')]
            : [];
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    public function failed(\Throwable $throwable): void
    {
        $delivery = NewsletterRequestDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        resolve(RecordDeliveryEvent::class)->handle(
            delivery: $delivery,
            event: 'failed',
            providerEvent: 'message.failed',
            occurredAt: CarbonImmutable::now(),
            payload: [
                'error_message' => $throwable->getMessage(),
                'error_class' => $throwable::class,
                'trace' => $throwable->getTraceAsString(),
            ],
        );
    }

    /**
     * @return array<int, Address>
     */
    private function replyToAddresses(): array
    {
        $replyTo = $this->request->headers['reply_to'] ?? null;

        if (! is_string($replyTo) || $replyTo === '') {
            return [];
        }

        return [$this->parseAddress($replyTo)];
    }

    private function parseAddress(string $value): Address
    {
        if (preg_match('/^(.+?)\s*<([^>]+)>$/', $value, $matches) !== 1) {
            return new Address($value);
        }

        return new Address(
            trim($matches[2]),
            trim($matches[1], ' "'),
        );
    }

    private function headerName(string $key): string
    {
        return str($key)
            ->replace('_', '-')
            ->title()
            ->value();
    }
}
