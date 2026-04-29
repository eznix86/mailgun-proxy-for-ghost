<?php

declare(strict_types=1);

namespace App\Data\Newsletter;

use Spatie\LaravelData\Data;

class NewsletterMessageData extends Data
{
    public function __construct(
        public string $from,
        public string $subject,
        public ?string $html,
        public ?string $text,
        public ?string $ampHtml,
    ) {
    }
}
