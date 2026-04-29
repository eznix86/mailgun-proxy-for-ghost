<?php

declare(strict_types=1);

use App\Http\Controllers\Mailgun\MailgunEventsController;
use App\Http\Controllers\Mailgun\MailgunMessagesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['mailgun.auth'])->group(function (): void {

    Route::post('/v3/{domain}/messages', MailgunMessagesController::class)
        ->name('mailgun.messages');

    Route::get('/v3/{domain}/events/{page?}', MailgunEventsController::class)
        ->where('page', '.*')
        ->name('mailgun.events');
});
