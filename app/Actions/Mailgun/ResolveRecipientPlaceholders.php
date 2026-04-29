<?php

declare(strict_types=1);

namespace App\Actions\Mailgun;

use App\Data\Newsletter\NewsletterMessageData;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;

class ResolveRecipientPlaceholders
{
    public function handle(NewsletterSendRequestData $request, NewsletterRecipientData $recipient): NewsletterSendRequestData
    {
        $replacements = collect($recipient->variables)
            ->mapWithKeys(fn (mixed $value, string $key) => [
                '%recipient.'.$key.'%' => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
            ])
            ->merge([
                '%tag_unsubscribe_email%' => '',
                '%tag_unsubscribe_url%' => '',
            ])
            ->all();

        $replace = fn (?string $content): ?string => $content === null
            ? null
            : strtr($content, $replacements);

        return new NewsletterSendRequestData(
            source: $request->source,
            message: new NewsletterMessageData(
                from: $replace($request->message->from) ?? $request->message->from,
                subject: $replace($request->message->subject) ?? $request->message->subject,
                html: $replace($request->message->html),
                text: $replace($request->message->text),
                ampHtml: $replace($request->message->ampHtml),
            ),
            recipients: $request->recipients,
            headers: collect($request->headers)
                ->map(fn (string $value, string $key): string => $this->resolveHeaderValue($key, $value, $replacements))
                ->all(),
            variables: $request->variables,
            options: $request->options,
            metadata: $request->metadata,
        );
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function resolveHeaderValue(string $key, string $value, array $replacements): string
    {
        $resolvedValue = trim(strtr($value, $replacements));

        if ($key !== 'list_unsubscribe') {
            return $resolvedValue;
        }

        return collect(explode(',', $resolvedValue))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '' && $item !== '<>')
            ->implode(', ');
    }
}
