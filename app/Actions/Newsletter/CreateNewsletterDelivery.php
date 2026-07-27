<?php

declare(strict_types=1);

namespace App\Actions\Newsletter;

use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;
use App\Models\NewsletterRequestAttempt;
use App\Models\NewsletterRequestDelivery;

class CreateNewsletterDelivery
{
    public function handle(NewsletterRequestAttempt $attempt, NewsletterSendRequestData $request, NewsletterRecipientData $recipient): NewsletterRequestDelivery
    {
        /** @var NewsletterRequestDelivery $delivery */
        $delivery = $attempt->deliveries()->create([
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

        return $delivery;
    }
}
