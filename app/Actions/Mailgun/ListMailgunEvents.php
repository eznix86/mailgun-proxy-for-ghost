<?php

declare(strict_types=1);

namespace App\Actions\Mailgun;

use App\Http\Resources\MailgunEventResource;
use App\Models\NewsletterRequestDeliveryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ListMailgunEvents
{
    /**
     * @return array{items: array<int, mixed>, paging: array<string, string>}
     */
    public function handle(Request $request, string $domain, ?string $page = null): array
    {
        $limit = max(1, min((int) $request->integer('limit', 100), 300));
        $offset = $this->decodePage($page);
        $ascending = $request->string('ascending')->value() === 'yes';

        $items = $this->query($request, $domain)
            ->orderBy('occurred_at', $ascending ? 'asc' : 'desc')
            ->orderBy('id', $ascending ? 'asc' : 'desc')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        $pageItems = $items->take($limit)->values();

        return [
            'items' => MailgunEventResource::collection($pageItems)->resolve($request),
            'paging' => $this->paging($domain, $offset, $limit, $pageItems->count(), $request->query()),
        ];
    }

    /**
     * @return Builder<NewsletterRequestDeliveryEvent>
     */
    private function query(Request $request, string $domain): Builder
    {
        return NewsletterRequestDeliveryEvent::query()
            ->with('delivery')
            ->whereHas('delivery', fn (Builder $query) => $query->where('domain', $domain))
            ->when($request->filled('event'), fn (Builder $query) => $query->whereIn('event', $this->events($request)))
            ->when($request->filled('message-id'), fn (Builder $query) => $query->whereHas(
                'delivery',
                fn (Builder $deliveryQuery) => $deliveryQuery->where('mailgun_message_id', (string) $request->string('message-id')),
            ))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereHas(
                'delivery',
                fn (Builder $deliveryQuery) => $deliveryQuery->where('recipient', (string) $request->string('to')),
            ))
            ->when($request->filled('begin'), fn (Builder $query) => $query->where('occurred_at', '>=', CarbonImmutable::createFromTimestampUTC((int) $request->integer('begin'))))
            ->when($request->filled('end'), fn (Builder $query) => $query->where('occurred_at', '<=', CarbonImmutable::createFromTimestampUTC((int) $request->integer('end'))));
    }

    /**
     * @param  array<string, mixed>  $queryParameters
     * @return array<string, string>
     */
    private function paging(string $domain, int $offset, int $limit, int $itemCount, array $queryParameters): array
    {
        return array_filter([
            'first' => $this->pageUrl($domain, 0, $queryParameters),
            'next' => $itemCount > 0 ? $this->pageUrl($domain, $offset + min($itemCount, $limit), $queryParameters) : null,
            'previous' => $offset > 0 ? $this->pageUrl($domain, max(0, $offset - $limit), $queryParameters) : null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function events(Request $request): array
    {
        return str((string) $request->string('event'))
            ->explode(' OR ')
            ->map(fn (string $event): string => trim($event))
            ->filter()
            ->values()
            ->all();
    }

    private function decodePage(?string $page): int
    {
        if (blank($page)) {
            return 0;
        }

        $decoded = base64_decode($page, true);

        if ($decoded === false || ! ctype_digit($decoded)) {
            return 0;
        }

        return (int) $decoded;
    }

    /**
     * @param  array<string, mixed>  $queryParameters
     */
    private function pageUrl(string $domain, int $offset, array $queryParameters): string
    {
        return route('mailgun.events', [
            'domain' => $domain,
            'page' => base64_encode((string) $offset),
            ...$queryParameters,
        ]);
    }
}
