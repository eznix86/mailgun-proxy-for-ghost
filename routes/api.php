<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\ResendWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/resend', ResendWebhookController::class)
    ->middleware('resend.webhook')
    ->name('webhooks.resend');
