<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mailgun;

use App\Actions\Mailgun\ListMailgunEvents;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailgunEventsController extends Controller
{
    public function __construct(private readonly ListMailgunEvents $listMailgunEvents)
    {
    }

    public function __invoke(Request $request, string $domain, ?string $page = null): JsonResponse
    {
        return response()->json($this->listMailgunEvents->handle($request, $domain, $page));
    }
}
