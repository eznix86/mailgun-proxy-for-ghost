<?php

declare(strict_types=1);

namespace App\Data\Newsletter;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class NewsletterSendOptionsData extends Data
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public array $tags,
        public ?bool $trackOpens,
        public ?CarbonImmutable $deliveryTime,
    ) {
    }

    public function isDeliveredLater(): bool
    {
        return $this->deliveryTime !== null;
    }

    public function deliversImmediately(): bool
    {
        return ! $this->isDeliveredLater();
    }
}
