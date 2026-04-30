<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('home route can be cached', function () {
    Artisan::call('route:clear');

    try {
        expect(Artisan::call('route:cache'))->toBe(0);
    } finally {
        Artisan::call('route:clear');
    }
});
