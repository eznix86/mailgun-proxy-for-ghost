<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');
});

test('mailgun suppression routes acknowledge removal with mailgun-faithful bodies', function (string $routeName, string $expectedMessage) {
    Log::shouldReceive('info')->once();

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->deleteJson(route($routeName, ['domain' => 'example.com', 'address' => 'user@example.com']))
        ->assertSuccessful()
        ->assertExactJson([
            'message' => $expectedMessage,
            'address' => 'user@example.com',
        ]);
})->with([
    'bounces' => ['mailgun.suppressions.bounces', 'Bounce has been removed'],
    'complaints' => ['mailgun.suppressions.complaints', 'Spam complaint has been removed'],
    'unsubscribes' => ['mailgun.suppressions.unsubscribes', 'Unsubscribe event has been removed'],
]);

test('mailgun suppression routes url-decode the encoded address for the body and log', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $context['type'] === 'bounces'
                && $context['address'] === 'user@example.com'
                && $context['domain'] === 'example.com';
        });

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->deleteJson('/v3/example.com/bounces/user%40example.com')
        ->assertSuccessful()
        ->assertExactJson([
            'message' => 'Bounce has been removed',
            'address' => 'user@example.com',
        ]);
});

test('mailgun suppression routes accept a plain unencoded address', function () {
    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->deleteJson('/v3/example.com/unsubscribes/subscriber@example.com')
        ->assertSuccessful()
        ->assertExactJson([
            'message' => 'Unsubscribe event has been removed',
            'address' => 'subscriber@example.com',
        ]);
});

test('mailgun suppression routes require valid basic auth', function (string $routeName) {
    $this->deleteJson(route($routeName, ['domain' => 'example.com', 'address' => 'user@example.com']))
        ->assertUnauthorized()
        ->assertExactJson([])
        ->assertHeader('WWW-Authenticate', 'Basic realm="Mailgun Proxy"');
})->with([
    'bounces' => ['mailgun.suppressions.bounces'],
    'complaints' => ['mailgun.suppressions.complaints'],
    'unsubscribes' => ['mailgun.suppressions.unsubscribes'],
]);
