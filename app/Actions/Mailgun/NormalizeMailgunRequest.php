<?php

declare(strict_types=1);

namespace App\Actions\Mailgun;

use App\Data\Newsletter\NewsletterMessageData;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterRequestSourceData;
use App\Data\Newsletter\NewsletterSendOptionsData;
use App\Data\Newsletter\NewsletterSendRequestData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class NormalizeMailgunRequest
{
    private const RESERVED_INPUT_KEYS = [
        'to',
        'from',
        'subject',
        'html',
        'text',
        'amp-html',
        'recipient-variables',
        'o:tag',
        'o:tracking-opens',
        'o:deliverytime',
    ];

    /**
     * @param  array<string, mixed>  $request
     */
    public function handle(array $request): NewsletterSendRequestData
    {
        $input = data_get($request, 'input', []);

        return new NewsletterSendRequestData(
            source: new NewsletterRequestSourceData(
                provider: 'mailgun',
                domain: (string) data_get($request, 'domain'),
                url: (string) data_get($request, 'url'),
                path: (string) data_get($request, 'path'),
            ),
            message: new NewsletterMessageData(
                from: (string) data_get($input, 'from', ''),
                subject: (string) data_get($input, 'subject', ''),
                html: data_get($input, 'html'),
                text: data_get($input, 'text'),
                ampHtml: data_get($input, 'amp-html'),
            ),
            recipients: $this->normalizeRecipients($input),
            headers: $this->normalizeByPrefix($input, 'h:'),
            variables: $this->normalizeByPrefix($input, 'v:'),
            options: new NewsletterSendOptionsData(
                tags: $this->normalizeTags(data_get($input, 'o:tag')),
                trackOpens: $this->normalizeBoolean(data_get($input, 'o:tracking-opens')),
                deliveryTime: $this->normalizeDeliveryTime(data_get($input, 'o:deliverytime')),
            ),
            metadata: $this->remainingMetadata($input),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, NewsletterRecipientData>
     */
    private function normalizeRecipients(array $input): array
    {
        $recipientVariables = data_get($input, 'recipient-variables');

        if (is_string($recipientVariables) && filled($recipientVariables)) {
            $decoded = json_decode($recipientVariables, true);

            if (is_array($decoded) && $decoded !== []) {
                return collect($decoded)
                    ->map(fn (mixed $variables, string $email) => new NewsletterRecipientData(
                        email: $email,
                        variables: is_array($variables) ? $variables : [],
                    ))
                    ->values()
                    ->all();
            }
        }

        $to = data_get($input, 'to');

        if (! is_string($to) || blank($to)) {
            return [];
        }

        return [new NewsletterRecipientData($to, [])];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function normalizeByPrefix(array $input, string $prefix): array
    {
        return collect($input)
            ->filter(fn (mixed $value, string $key) => str_starts_with($key, $prefix))
            ->mapWithKeys(fn (mixed $value, string $key) => [
                $this->normalizePrefixedKey(Str::after($key, $prefix)) => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
            ])->all();
    }

    private function normalizePrefixedKey(string $key): string
    {
        return str($key)
            ->replace('-', ' ')
            ->snake()
            ->value();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags) && filled($tags)) {
            return [$tags];
        }

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, is_string(...)));
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 'true', 'yes', 1, '1' => true,
            false, 'false', 'no', 0, '0' => false,
            default => null,
        };
    }

    private function normalizeDeliveryTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function remainingMetadata(array $input): array
    {
        return collect($input)
            ->reject(fn (mixed $value, string $key) => in_array($key, self::RESERVED_INPUT_KEYS, true) || str_starts_with($key, 'h:') || str_starts_with($key, 'v:'))
            ->all();
    }
}
