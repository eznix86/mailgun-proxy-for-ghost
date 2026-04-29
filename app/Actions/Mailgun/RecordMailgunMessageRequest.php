<?php

declare(strict_types=1);

namespace App\Actions\Mailgun;

use App\Models\NewsletterRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class RecordMailgunMessageRequest
{
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'php-auth-user',
        'php-auth-pw',
    ];

    public function handle(Request $request, string $domain): NewsletterRequest
    {
        return NewsletterRequest::query()->create([
            'original_request' => [
                'provider' => 'mailgun',
                'route' => $request->route()?->getName(),
                'url' => $request->fullUrl(),
                'path' => '/'.$request->path(),
                'method' => $request->method(),
                'domain' => $domain,
                'headers' => collect($request->headers->all())->except(self::SENSITIVE_HEADERS)->all(),
                'query' => $request->query(),
                'input' => $request->all(),
                'files' => $this->files($request),
            ],
        ]);
    }

    /**
     * @return array<string, array{name: string, mime: string|null, size: int|false, sent_as: string, temporary_path: string}>
     */
    private function files(Request $request): array
    {
        return collect($request->allFiles())
            ->mapWithKeys(fn (UploadedFile $file, string $key): array => [$key => [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'sent_as' => $key,
                'temporary_path' => $file->getPathname(),
            ]])
            ->all();
    }
}
