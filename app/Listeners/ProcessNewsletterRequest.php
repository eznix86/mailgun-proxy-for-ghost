<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Mailgun\NormalizeMailgunRequest;
use App\Contracts\OutboxProvider;
use App\Events\NewsletterRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ProcessNewsletterRequest implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NormalizeMailgunRequest $normalizeMailgunRequest,
        private readonly OutboxProvider $outboxProvider,
    ) {
    }

    public function handle(NewsletterRequested $event): void
    {
        $attempt = $event->newsletterRequest->attempts()->create([
            'started_at' => now(),
        ]);

        try {
            $request = $this->normalizeMailgunRequest->handle($event->newsletterRequest->original_request);

            $this->outboxProvider->send($request, $attempt);

            $attempt->forceFill([
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            $attempt->forceFill([
                'finished_at' => now(),
                'error_message' => $throwable->getMessage(),
                'error_class' => $throwable::class,
                'context' => [
                    'trace' => $throwable->getTraceAsString(),
                ],
            ])->save();

            throw $throwable;
        }
    }
}
