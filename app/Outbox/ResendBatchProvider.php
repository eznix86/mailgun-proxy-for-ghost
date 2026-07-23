<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Actions\Mailgun\ResolveRecipientPlaceholders;
use App\Actions\Newsletter\CreateNewsletterDelivery;
use App\Actions\Newsletter\RecordDeliveryEvent;
use App\Contracts\OutboxProvider;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;
use App\Models\NewsletterRequestAttempt;
use App\Models\NewsletterRequestDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sends a newsletter through Resend's batch endpoint (POST /emails/batch),
 * replacing the per-recipient mailable flow for the Resend provider. A single
 * 10k-recipient newsletter collapses from 10k queued sends into 100 batch
 * calls, expanding %recipient.x% placeholders per recipient exactly as the
 * per-recipient path does.
 */
class ResendBatchProvider implements OutboxProvider
{
    /**
     * Resend's hard limit: at most 100 emails per batch call.
     */
    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly ResolveRecipientPlaceholders $resolveRecipientPlaceholders,
        private readonly CreateNewsletterDelivery $createNewsletterDelivery,
        private readonly RecordDeliveryEvent $recordDeliveryEvent,
    ) {}

    public function send(NewsletterSendRequestData $request, NewsletterRequestAttempt $attempt): void
    {
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, (int) config('services.outbox.resend.batch_size', self::MAX_BATCH_SIZE)));
        $pauseMs = max(0, (int) config('services.outbox.resend.batch_pause_ms', 200));

        $chunks = collect($request->recipients)->chunk($batchSize)->values();

        foreach ($chunks as $chunkIndex => $chunk) {
            if ($chunkIndex > 0 && $pauseMs > 0) {
                Sleep::for($pauseMs)->milliseconds();
            }

            $this->sendChunk($request, $attempt, $chunk->values(), (int) $chunkIndex);
        }
    }

    /**
     * @param  Collection<int, NewsletterRecipientData>  $recipients
     */
    private function sendChunk(NewsletterSendRequestData $request, NewsletterRequestAttempt $attempt, Collection $recipients, int $chunkIndex): void
    {
        /** @var array<int, NewsletterRequestDelivery> $deliveries */
        $deliveries = [];

        /** @var array<int, array<string, mixed>> $payloads */
        $payloads = [];

        foreach ($recipients as $recipient) {
            $resolved = $this->resolveRecipientPlaceholders->handle($request, $recipient);

            $deliveries[] = $this->createNewsletterDelivery->handle($attempt, $request, $recipient);
            $payloads[] = $this->buildPayload($resolved, $recipient);
        }

        try {
            $response = $this->dispatch($payloads, $this->idempotencyKey($attempt, $chunkIndex));

            $this->recordAccepted($deliveries, $response);
        } catch (Throwable $throwable) {
            $this->recordFailed($deliveries, $throwable);

            throw $throwable;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function dispatch(array $payloads, string $idempotencyKey): Response
    {
        $baseUrl = rtrim((string) config('services.resend.base_url', 'https://api.resend.com'), '/');

        return Http::withToken((string) config('services.resend.key'))
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
                'x-batch-validation' => 'strict',
            ])
            ->acceptJson()
            ->asJson()
            ->post($baseUrl.'/emails/batch', $payloads)
            ->throw();
    }

    /**
     * Records one accepted delivery per recipient, matching each response id to
     * its recipient by request order (Resend returns ids in the same order the
     * payloads were sent).
     *
     * @param  array<int, NewsletterRequestDelivery>  $deliveries
     */
    private function recordAccepted(array $deliveries, Response $response): void
    {
        $ids = $response->json('data');
        $ids = is_array($ids) ? array_values($ids) : [];

        foreach ($deliveries as $index => $delivery) {
            $entry = $ids[$index] ?? null;
            $providerMessageId = is_array($entry) && isset($entry['id']) && is_string($entry['id'])
                ? $entry['id']
                : null;

            $this->recordDeliveryEvent->handle(
                delivery: $delivery,
                event: 'accepted',
                providerEvent: 'message.sent',
                providerEventId: $providerMessageId !== null ? 'message.sent:'.$providerMessageId : null,
                payload: $providerMessageId !== null ? ['provider_message_id' => $providerMessageId] : null,
            );
        }
    }

    /**
     * @param  array<int, NewsletterRequestDelivery>  $deliveries
     */
    private function recordFailed(array $deliveries, Throwable $throwable): void
    {
        foreach ($deliveries as $delivery) {
            $this->recordDeliveryEvent->handle(
                delivery: $delivery,
                event: 'failed',
                providerEvent: 'message.failed',
                payload: [
                    'error_message' => $throwable->getMessage(),
                    'error_class' => $throwable::class,
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(NewsletterSendRequestData $resolved, NewsletterRecipientData $recipient): array
    {
        return array_filter([
            'from' => $resolved->message->from,
            'to' => [$recipient->email],
            'subject' => $resolved->message->subject,
            'html' => $resolved->message->html,
            'text' => $resolved->message->text,
            'reply_to' => $this->replyTo($resolved),
            'headers' => $this->headers($resolved),
            'scheduled_at' => $this->scheduledAt($resolved),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function replyTo(NewsletterSendRequestData $resolved): ?string
    {
        $replyTo = $resolved->headers['reply_to'] ?? null;

        return is_string($replyTo) && $replyTo !== '' ? $replyTo : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(NewsletterSendRequestData $resolved): array
    {
        return collect($resolved->headers)
            ->reject(fn (string $value, string $key): bool => in_array($key, ['reply_to', 'sender'], true))
            ->mapWithKeys(fn (string $value, string $key): array => [$this->headerName($key) => $value])
            ->all();
    }

    private function headerName(string $key): string
    {
        return str($key)
            ->replace('_', '-')
            ->title()
            ->value();
    }

    private function scheduledAt(NewsletterSendRequestData $resolved): ?string
    {
        if (! $resolved->options->isDeliveredLater()) {
            return null;
        }

        return $resolved->options->deliveryTime?->toIso8601String();
    }

    /**
     * Deterministic per-chunk key anchored on the newsletter request id (stable
     * across job retries — a retry creates a fresh attempt, so anchoring on the
     * attempt id would let Resend re-send an already-sent chunk).
     */
    private function idempotencyKey(NewsletterRequestAttempt $attempt, int $chunkIndex): string
    {
        return sprintf('mgw-batch-%s-%d', (string) $attempt->getAttribute('newsletter_request_id'), $chunkIndex);
    }
}
