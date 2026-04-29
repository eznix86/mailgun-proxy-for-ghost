<?php

declare(strict_types=1);

namespace App\Data\Newsletter;

use Spatie\LaravelData\Data;

class NewsletterRecipientData extends Data
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public string $email,
        public array $variables,
    ) {
    }
}
