<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsletterRequest;
use App\Models\NewsletterRequestAttempt;
use App\Models\NewsletterRequestDelivery;
use App\Models\NewsletterRequestDeliveryEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('dashboard', [
            'summary' => $this->summary(),
            'alerts' => $this->alerts(),
            'recentFailures' => $this->recentFailures(),
            'delivery' => $this->delivery(),
            'failureReasons' => $this->failureReasons(),
            'suppressions' => $this->suppressions(),
            'requests' => Inertia::scroll(fn () => $this->requests()),
        ]);
    }

    /**
     * @return array<int, array{label: string, value: string, detail: string, tone: string}>
     */
    private function summary(): array
    {
        $deliveries = NewsletterRequestDelivery::query()->count();
        $failedDeliveries = NewsletterRequestDelivery::query()
            ->whereIn('latest_event', ['failed', 'rejected'])
            ->count();

        return [
            [
                'label' => 'Queued requests',
                'value' => (string) NewsletterRequest::query()->doesntHave('attempts')->count(),
                'detail' => 'Waiting for the first send attempt',
                'tone' => 'neutral',
            ],
            [
                'label' => 'Processing',
                'value' => (string) NewsletterRequestAttempt::query()->whereNull('finished_at')->count(),
                'detail' => 'Attempts currently open',
                'tone' => 'info',
            ],
            [
                'label' => 'Tracked deliveries',
                'value' => (string) $deliveries,
                'detail' => 'Recipient-level delivery rows',
                'tone' => 'success',
            ],
            [
                'label' => 'Failure rate',
                'value' => $deliveries === 0 ? '0%' : round(($failedDeliveries / $deliveries) * 100, 1).'%',
                'detail' => $failedDeliveries.' failed or rejected deliveries',
                'tone' => $failedDeliveries > 0 ? 'danger' : 'success',
            ],
            [
                'label' => 'Complaints',
                'value' => (string) NewsletterRequestDeliveryEvent::query()->where('event', 'complained')->count(),
                'detail' => 'Recipient complaint events',
                'tone' => 'warning',
            ],
        ];
    }

    /**
     * @return array<int, array{message: string, level: string}>
     */
    private function alerts(): array
    {
        $failedDeliveries = NewsletterRequestDelivery::query()
            ->whereIn('latest_event', ['failed', 'rejected'])
            ->count();
        $processingAttempts = NewsletterRequestAttempt::query()->whereNull('finished_at')->count();
        $queuedRequests = NewsletterRequest::query()->doesntHave('attempts')->count();

        return collect([
            $failedDeliveries > 0 ? [
                'message' => $failedDeliveries.' deliveries need attention.',
                'level' => 'danger',
            ] : null,
            $processingAttempts > 0 ? [
                'message' => $processingAttempts.' attempts are currently processing.',
                'level' => 'warning',
            ] : null,
            $queuedRequests > 0 ? [
                'message' => $queuedRequests.' requests are waiting for a send attempt.',
                'level' => 'warning',
            ] : null,
        ])->filter()->values()->all() ?: [[
            'message' => 'No delivery alerts detected.',
            'level' => 'ok',
        ]];
    }

    /**
     * @return array<int, array{time: string|null, event: string, recipient: string, reason: string}>
     */
    private function recentFailures(): array
    {
        return NewsletterRequestDeliveryEvent::query()
            ->with('delivery:id,recipient')
            ->whereIn('event', ['failed', 'rejected', 'complained'])
            ->latest('occurred_at')
            ->limit(5)
            ->get()
            ->map(fn (NewsletterRequestDeliveryEvent $event): array => [
                'time' => $event->occurred_at?->toIso8601String(),
                'event' => $event->event,
                'recipient' => $event->delivery?->recipient ?? 'unknown recipient',
                'reason' => (string) ($event->payload['reason'] ?? $event->provider_event ?? $event->severity ?? 'No reason recorded'),
            ])
            ->all();
    }

    /**
     * @return array{metrics: array<int, array{label: string, value: string, detail: string, tone: string}>, timeline: array<int, array{date: string, sent: int, delivered: int, opened: int, clicked: int, failed: int, open_rate: float, click_rate: float, failure_rate: float}>}
     */
    private function delivery(): array
    {
        $deliveries = NewsletterRequestDelivery::query()->count();
        $delivered = NewsletterRequestDelivery::query()->whereNotNull('delivered_at')->count();
        $opened = NewsletterRequestDeliveryEvent::query()->where('event', 'opened')->count();
        $clicked = NewsletterRequestDeliveryEvent::query()->where('event', 'clicked')->count();

        return [
            'metrics' => [
                [
                    'label' => 'Delivered',
                    'value' => (string) $delivered,
                    'detail' => 'Sent '.$deliveries,
                    'tone' => 'success',
                ],
                [
                    'label' => 'Avg. open rate',
                    'value' => $delivered === 0 ? '0%' : round(($opened / $delivered) * 100, 1).'%',
                    'detail' => $opened.' open events',
                    'tone' => 'info',
                ],
                [
                    'label' => 'Avg. click rate',
                    'value' => $delivered === 0 ? '0%' : round(($clicked / $delivered) * 100, 1).'%',
                    'detail' => $clicked.' click events',
                    'tone' => 'neutral',
                ],
            ],
            'timeline' => $this->deliveryTimeline(),
        ];
    }

    /**
     * @return array<int, array{date: string, sent: int, delivered: int, opened: int, clicked: int, failed: int, open_rate: float, click_rate: float, failure_rate: float}>
     */
    private function deliveryTimeline(): array
    {
        $start = CarbonImmutable::now()->subDays(29)->startOfDay();
        $end = CarbonImmutable::now()->endOfDay();
        $deliveries = NewsletterRequestDelivery::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at', 'delivered_at', 'latest_event']);
        $events = NewsletterRequestDeliveryEvent::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['event', 'occurred_at']);

        return collect(CarbonPeriod::create($start, '1 day', $end))
            ->map(function ($date) use ($deliveries, $events): array {
                $dayDeliveries = $deliveries->filter(fn (NewsletterRequestDelivery $delivery): bool => $delivery->created_at?->isSameDay($date) ?? false);
                $dayEvents = $events->filter(fn (NewsletterRequestDeliveryEvent $event): bool => $event->occurred_at?->isSameDay($date) ?? false);
                $sent = $dayDeliveries->count();
                $delivered = $dayDeliveries->whereNotNull('delivered_at')->count();
                $opened = $dayEvents->where('event', 'opened')->count();
                $clicked = $dayEvents->where('event', 'clicked')->count();
                $failed = $dayDeliveries->whereIn('latest_event', ['failed', 'rejected'])->count();

                return [
                    'date' => $date->format('M j'),
                    'sent' => $sent,
                    'delivered' => $delivered,
                    'opened' => $opened,
                    'clicked' => $clicked,
                    'failed' => $failed,
                    'open_rate' => $delivered === 0 ? 0.0 : round(($opened / $delivered) * 100, 1),
                    'click_rate' => $delivered === 0 ? 0.0 : round(($clicked / $delivered) * 100, 1),
                    'failure_rate' => $sent === 0 ? 0.0 : round(($failed / $sent) * 100, 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{reason: string, count: int}>
     */
    private function failureReasons(): array
    {
        return NewsletterRequestDeliveryEvent::query()
            ->whereIn('event', ['failed', 'rejected', 'complained'])
            ->latest('occurred_at')
            ->get()
            ->groupBy(fn (NewsletterRequestDeliveryEvent $event): string => (string) ($event->payload['reason'] ?? $event->provider_event ?? $event->severity ?? 'No reason recorded'))
            ->map(fn ($events, string $reason): array => [
                'reason' => $reason,
                'count' => $events->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(10)
            ->all();
    }

    /**
     * @return array{metrics: array<int, array{label: string, value: string, detail: string, tone: string}>, rows: array<int, array{email: string, type: string, reason: string, created_at: string|null}>}
     */
    private function suppressions(): array
    {
        $rows = NewsletterRequestDeliveryEvent::query()
            ->with('delivery:id,recipient')
            ->whereIn('event', ['failed', 'rejected', 'complained', 'unsubscribed'])
            ->latest('occurred_at')
            ->limit(12)
            ->get()
            ->map(fn (NewsletterRequestDeliveryEvent $event): array => [
                'email' => $event->delivery?->recipient ?? 'unknown recipient',
                'type' => match ($event->event) {
                    'complained' => 'complaints',
                    'unsubscribed' => 'unsubscribes',
                    default => 'bounces',
                },
                'reason' => (string) ($event->payload['reason'] ?? $event->provider_event ?? $event->severity ?? 'No reason recorded'),
                'created_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->all();

        return [
            'metrics' => [
                ['label' => 'Bounces', 'value' => (string) collect($rows)->where('type', 'bounces')->count(), 'detail' => 'Failed or rejected recipients', 'tone' => 'danger'],
                ['label' => 'Complaints', 'value' => (string) collect($rows)->where('type', 'complaints')->count(), 'detail' => 'Spam complaint recipients', 'tone' => 'warning'],
                ['label' => 'Unsubscribes', 'value' => (string) collect($rows)->where('type', 'unsubscribes')->count(), 'detail' => 'Opt-out events', 'tone' => 'neutral'],
            ],
            'rows' => $rows,
        ];
    }

    private function requests()
    {
        return NewsletterRequest::query()
            ->with([
                'latestAttempt',
                'attempts' => fn ($query) => $query
                    ->latest('id')
                    ->with([
                        'deliveries' => fn ($deliveryQuery) => $deliveryQuery
                            ->latest('id')
                            ->with([
                                'events' => fn ($eventQuery) => $eventQuery->latest('id'),
                            ]),
                    ]),
            ])
            ->latest('id')
            ->paginate(10)
            ->through(fn (NewsletterRequest $request): array => [
                'id' => $request->id,
                'status' => $request->status?->value,
                'created_at' => $request->created_at?->toIso8601String(),
                'updated_at' => $request->updated_at?->toIso8601String(),
                'domain' => (string) ($request->original_request['domain'] ?? ''),
                'subject' => (string) ($request->original_request['input']['subject'] ?? ''),
                'from' => (string) ($request->original_request['input']['from'] ?? ''),
                'to' => (string) ($request->original_request['input']['to'] ?? ''),
                'attempts' => $request->attempts->map(fn ($attempt): array => [
                    'id' => $attempt->id,
                    'started_at' => $attempt->started_at?->toIso8601String(),
                    'finished_at' => $attempt->finished_at?->toIso8601String(),
                    'error_message' => $attempt->error_message,
                    'error_class' => $attempt->error_class,
                    'deliveries' => $attempt->deliveries->map(fn ($delivery): array => [
                        'id' => $delivery->id,
                        'recipient' => $delivery->recipient,
                        'provider' => $delivery->provider,
                        'provider_message_id' => $delivery->provider_message_id,
                        'latest_event' => $delivery->latest_event,
                        'latest_severity' => $delivery->latest_severity,
                        'accepted_at' => $delivery->accepted_at?->toIso8601String(),
                        'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                        'failed_at' => $delivery->failed_at?->toIso8601String(),
                        'events' => $delivery->events->map(fn ($event): array => [
                            'id' => $event->id,
                            'event' => $event->event,
                            'provider_event' => $event->provider_event,
                            'severity' => $event->severity,
                            'occurred_at' => $event->occurred_at?->toIso8601String(),
                        ])->values()->all(),
                    ])->values()->all(),
                ])->values()->all(),
            ]);
    }
}
