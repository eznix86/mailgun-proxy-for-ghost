<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RetryNewsletterRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('health', HealthController::class)->name('health');
    Route::post('newsletter-requests/{newsletterRequest}/retry', RetryNewsletterRequestController::class)
        ->name('newsletter-requests.retry');
});

require __DIR__.'/settings.php';
