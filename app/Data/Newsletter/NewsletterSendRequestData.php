<?php

declare(strict_types=1);

namespace App\Data\Newsletter;

use Spatie\LaravelData\Data;

class NewsletterSendRequestData extends Data
{
    /**
     * @param  array<int, NewsletterRecipientData>  $recipients
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public NewsletterRequestSourceData $source,
        public NewsletterMessageData $message,
        public array $recipients,
        public array $headers,
        public array $variables,
        public NewsletterSendOptionsData $options,
        public array $metadata,
    ) {
    }
}
