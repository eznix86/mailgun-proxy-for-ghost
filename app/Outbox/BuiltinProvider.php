<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Actions\Mailgun\ResolveRecipientPlaceholders;
use App\Contracts\OutboxProvider;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;
use App\Mail\GhostNewsletter;
use App\Models\NewsletterRequestDelivery;
use App\Models\NewsletterRequestAttempt;
use Illuminate\Support\Facades\Mail;

class BuiltinProvider implements OutboxProvider
{
    public function __construct(
        private readonly ResolveRecipientPlaceholders $resolveRecipientPlaceholders,
    ) {
    }

    public function send(NewsletterSendRequestData $request, NewsletterRequestAttempt $attempt): void
    {
        foreach ($request->recipients as $recipient) {
            $delivery = $this->createDelivery($attempt, $request, $recipient);

            $mailable = new GhostNewsletter(
                $this->resolveRecipientPlaceholders->handle($request, $recipient),
                $recipient,
                $delivery->id,
            );

            $this->sendMailable($request, $recipient, $mailable);
        }
    }

    private function createDelivery(NewsletterRequestAttempt $attempt, NewsletterSendRequestData $request, NewsletterRecipientData $recipient): NewsletterRequestDelivery
    {
        return $attempt->deliveries()->create([
            'domain' => $request->source->domain,
            'provider' => (string) config('services.outbox.provider', config('mail.default')),
            'recipient' => $recipient->email,
            'mailgun_message_id' => $request->variables['email_id'] ?? null,
            'from' => $request->message->from,
            'subject' => $request->message->subject,
            'tags' => $request->options->tags,
            'user_variables' => $request->variables,
            'recipient_variables' => $recipient->variables,
        ]);
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
