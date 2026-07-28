<?php

declare(strict_types=1);

use App\Enums\MailgunSuppressionType;
use App\Http\Controllers\Mailgun\MailgunEventsController;
use App\Http\Controllers\Mailgun\MailgunMessagesController;
use App\Http\Controllers\Mailgun\MailgunSuppressionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['mailgun.auth'])->group(function (): void {

    Route::post('/v3/{domain}/messages', MailgunMessagesController::class)
        ->name('mailgun.messages');

    Route::get('/v3/{domain}/events/{page?}', MailgunEventsController::class)
        ->where('page', '.*')
        ->name('mailgun.events');

    Route::delete('/v3/{domain}/bounces/{address}', MailgunSuppressionsController::class)
        ->defaults('type', MailgunSuppressionType::Bounces->value)
        ->name('mailgun.suppressions.bounces');

    Route::delete('/v3/{domain}/complaints/{address}', MailgunSuppressionsController::class)
        ->defaults('type', MailgunSuppressionType::Complaints->value)
        ->name('mailgun.suppressions.complaints');

    Route::delete('/v3/{domain}/unsubscribes/{address}', MailgunSuppressionsController::class)
        ->defaults('type', MailgunSuppressionType::Unsubscribes->value)
        ->name('mailgun.suppressions.unsubscribes');
});
