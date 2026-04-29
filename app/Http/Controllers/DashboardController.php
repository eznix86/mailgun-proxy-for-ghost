<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsletterRequest;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('dashboard', [
            'requests' => Inertia::scroll(fn () => $this->requests()),
        ]);
    }

    private function requests()
    {
        return NewsletterRequest::query()
            ->with([
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
                'status' => $request->status->value,
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
