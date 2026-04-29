<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mailgun;

use App\Actions\Mailgun\RecordMailgunMessageRequest;
use App\Events\NewsletterRequested;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailgunMessagesController extends Controller
{
    public function __construct(private readonly RecordMailgunMessageRequest $recordMailgunMessageRequest)
    {
    }

    public function __invoke(Request $request, string $domain): JsonResponse
    {
        $newsletterRequest = $this->recordMailgunMessageRequest->handle($request, $domain);

        event(new NewsletterRequested($newsletterRequest));

        return response()->json([
            'id' => 'message-id',
            'message' => 'Queued. Thank you.',
        ]);
    }
}
