<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RetryNewsletterRequestController;
use App\Support\Registration;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => fn (): bool => Features::enabled(Features::registration()) && Registration::available(),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('newsletter-requests/{newsletterRequest}/retry', RetryNewsletterRequestController::class)
        ->name('newsletter-requests.retry');
});

require __DIR__.'/settings.php';
