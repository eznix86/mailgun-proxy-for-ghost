<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mailgun;

use App\Actions\Mailgun\AcknowledgeMailgunSuppression;
use App\Enums\MailgunSuppressionType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles Ghost's Mailgun suppression-removal calls
 * (DELETE /v3/{domain}/{bounces|complaints|unsubscribes}/{address}) as logged no-ops.
 *
 * @see AcknowledgeMailgunSuppression for the rationale behind the no-op semantics.
 */
class MailgunSuppressionsController extends Controller
{
    public function __construct(private readonly AcknowledgeMailgunSuppression $acknowledgeMailgunSuppression) {}

    public function __invoke(Request $request, string $domain, string $address, string $type): JsonResponse
    {
        return response()->json(
            $this->acknowledgeMailgunSuppression->handle(
                MailgunSuppressionType::from($type),
                $domain,
                $address,
            ),
        );
    }
}
