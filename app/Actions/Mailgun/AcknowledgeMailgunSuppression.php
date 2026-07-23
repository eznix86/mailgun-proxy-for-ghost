<?php

declare(strict_types=1);

namespace App\Actions\Mailgun;

use App\Enums\MailgunSuppressionType;
use Illuminate\Support\Facades\Log;

/**
 * Acknowledges Ghost's Mailgun suppression-removal calls (bounces, complaints, unsubscribes)
 * as logged no-ops.
 *
 * Resend exposes no public API for removing suppressions (they are cleared from the dashboard
 * only) and this proxy keeps no suppression list of its own, so acknowledging and logging is the
 * correct semantic: Ghost only needs any 2xx, and the log line preserves observability for
 * operators who must clear a Resend suppression manually.
 */
class AcknowledgeMailgunSuppression
{
    /**
     * @return array{message: string, address: string}
     */
    public function handle(MailgunSuppressionType $type, string $domain, string $address): array
    {
        Log::info('Acknowledged Mailgun suppression removal as a no-op.', [
            'type' => $type->value,
            'address' => $address,
            'domain' => $domain,
        ]);

        return [
            'message' => $type->removalMessage(),
            'address' => $address,
        ];
    }
}
