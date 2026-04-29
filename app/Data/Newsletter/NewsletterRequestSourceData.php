<?php

declare(strict_types=1);

namespace App\Data\Newsletter;

use Spatie\LaravelData\Data;

class NewsletterRequestSourceData extends Data
{
    public function __construct(
        public string $provider,
        public string $domain,
        public string $url,
        public string $path,
    ) {
    }
}
