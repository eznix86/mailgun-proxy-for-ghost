<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Actions\Mailgun\ResolveRecipientPlaceholders;
use App\Actions\Newsletter\CreateNewsletterDelivery;
use App\Contracts\OutboxProvider;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;
use App\Mail\GhostNewsletter;
use App\Models\NewsletterRequestAttempt;
use Illuminate\Support\Facades\Mail;

class BuiltinProvider implements OutboxProvider
{
    public function __construct(
        private readonly ResolveRecipientPlaceholders $resolveRecipientPlaceholders,
        private readonly CreateNewsletterDelivery $createNewsletterDelivery,
        private readonly ResendBatchProvider $resendBatchProvider,
    ) {}

    public function send(NewsletterSendRequestData $request, NewsletterRequestAttempt $attempt): void
    {
        if ($this->shouldUseResendBatch()) {
            $this->resendBatchProvider->send($request, $attempt);

            return;
        }

        foreach ($request->recipients as $recipient) {
            $delivery = $this->createNewsletterDelivery->handle($attempt, $request, $recipient);

            $mailable = new GhostNewsletter(
                $this->resolveRecipientPlaceholders->handle($request, $recipient),
                $recipient,
                $delivery->id,
            );

            $this->sendMailable($request, $recipient, $mailable);
        }
    }

    private function shouldUseResendBatch(): bool
    {
        return config('services.outbox.provider') === 'resend'
            && (bool) config('services.outbox.resend.batch', true);
    }

    private function sendMailable(NewsletterSendRequestData $request, NewsletterRecipientData $recipient, GhostNewsletter $mailable): void
    {
        $pendingMail = Mail::to($recipient->email);

        if ($request->options->isDeliveredLater()) {
            $pendingMail->later($request->options->deliveryTime, $mailable);

            return;
        }

        $pendingMail->send($mailable);
    }
}
