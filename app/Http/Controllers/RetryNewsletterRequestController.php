<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\NewsletterRequested;
use App\Models\NewsletterRequest;
use Illuminate\Http\RedirectResponse;

class RetryNewsletterRequestController extends Controller
{
    public function __invoke(NewsletterRequest $newsletterRequest): RedirectResponse
    {
        event(new NewsletterRequested($newsletterRequest));

        return back()->with('success', 'Newsletter request queued for retry.');
    }
}
