<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Newsletter\NewsletterSendRequestData;
use App\Models\NewsletterRequestAttempt;

interface OutboxProvider
{
    public function send(NewsletterSendRequestData $request, NewsletterRequestAttempt $attempt): void;
}
