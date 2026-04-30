<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsletterRequest;
use App\Models\NewsletterRequestAttempt;
use App\Models\NewsletterRequestDelivery;
use App\Models\NewsletterRequestDeliveryEvent;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('health', [
            'status' => $this->status(),
            'signals' => $this->signals(),
            'checks' => $this->checks(),
        ]);
    }

    /**
     * @return array{state: string, updated_at: string}
     */
    private function status(): array
    {
        $hasFailures = NewsletterRequestDelivery::query()
            ->whereIn('latest_event', ['failed', 'rejected'])
            ->exists();

        return [
            'state' => $hasFailures ? 'warning' : 'ok',
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{signal: string, value: string}>
     */
    private function signals(): array
    {
        return [
            ['signal' => 'Outbox provider', 'value' => (string) config('services.outbox.provider')],
            ['signal' => 'Default mailer', 'value' => (string) config('mail.default')],
            ['signal' => 'Resend API key configured', 'value' => config('services.resend.key') ? 'Yes' : 'No'],
            ['signal' => 'Resend webhook secret configured', 'value' => config('services.resend.webhook_secret') ? 'Yes' : 'No'],
            ['signal' => 'Newsletter request queue', 'value' => (string) NewsletterRequest::query()->doesntHave('attempts')->count()],
            ['signal' => 'Processing attempts', 'value' => (string) NewsletterRequestAttempt::query()->whereNull('finished_at')->count()],
            ['signal' => 'Tracked deliveries', 'value' => (string) NewsletterRequestDelivery::query()->count()],
            ['signal' => 'Failed deliveries', 'value' => (string) NewsletterRequestDelivery::query()->whereIn('latest_event', ['failed', 'rejected'])->count()],
            ['signal' => 'Last request update', 'value' => $this->latestTimestamp(NewsletterRequest::query()->max('updated_at'))],
            ['signal' => 'Last delivery event', 'value' => $this->latestTimestamp(NewsletterRequestDeliveryEvent::query()->max('occurred_at'))],
        ];
    }

    /**
     * @return array<int, array{check: string, status: string, detail: string}>
     */
    private function checks(): array
    {
        $failedDeliveries = NewsletterRequestDelivery::query()->whereIn('latest_event', ['failed', 'rejected'])->count();
        $deliveries = NewsletterRequestDelivery::query()->count();
        $complaints = NewsletterRequestDeliveryEvent::query()->where('event', 'complained')->count();

        return [
            [
                'check' => 'Service configured',
                'status' => config('services.outbox.provider') ? 'ok' : 'warn',
                'detail' => 'Outbox provider is set to '.config('services.outbox.provider', 'not configured').'.',
            ],
            [
                'check' => 'Resend credentials',
                'status' => config('services.resend.key') ? 'ok' : 'warn',
                'detail' => config('services.resend.key') ? 'Resend API key is configured.' : 'Set RESEND_API_KEY before sending through Resend.',
            ],
            [
                'check' => 'Webhook verification',
                'status' => config('services.resend.webhook_secret') ? 'ok' : 'warn',
                'detail' => config('services.resend.webhook_secret') ? 'Webhook secret is configured.' : 'Set RESEND_WEBHOOK_SECRET to verify delivery events.',
            ],
            [
                'check' => 'Delivery failures',
                'status' => $failedDeliveries === 0 ? 'ok' : 'warn',
                'detail' => $failedDeliveries.' of '.$deliveries.' deliveries are failed or rejected.',
            ],
            [
                'check' => 'Complaint events',
                'status' => $complaints === 0 ? 'ok' : 'warn',
                'detail' => $complaints.' complaint events are recorded.',
            ],
        ];
    }

    private function latestTimestamp(mixed $value): string
    {
        return $value === null ? 'not yet' : (string) $value;
    }
}
