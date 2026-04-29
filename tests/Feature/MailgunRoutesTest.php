<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

test('mailgun routes require valid basic auth', function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $this->postJson(route('mailgun.messages', ['domain' => 'example.com']))
        ->assertUnauthorized()
        ->assertExactJson([])
        ->assertHeader('WWW-Authenticate', 'Basic realm="Mailgun Proxy"');
});

test('mailgun messages route accepts valid basic auth', function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->postJson(route('mailgun.messages', ['domain' => 'example.com']), [
        'subject' => 'Hello',
    ])->assertSuccessful()
        ->assertJson([
            'id' => 'message-id',
            'message' => 'Queued. Thank you.',
        ]);
});

test('mailgun events route accepts valid basic auth with optional page', function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com', 'page' => 'next-page']))
        ->assertSuccessful()
        ->assertJsonStructure([
            'items',
            'paging' => ['first'],
        ]);
});

test('resend webhook route rejects unsigned requests', function () {
    config()->set('services.resend.webhook_secret', 'whsec_'.base64_encode('test-secret'));
    config()->set('services.resend.key', 'test-resend-key');

    $this->postJson(route('webhooks.resend'))
        ->assertUnauthorized();
});

test('resend webhook route requires configured webhook secret', function () {
    config()->set('services.resend.webhook_secret', null);

    $this->postJson(route('webhooks.resend'))
        ->assertServiceUnavailable();
});

test('mailgun v3 routes render validation exceptions as 400 json', function () {
    Route::post('/v3/test-validation', function () {
        throw ValidationException::withMessages([
            'message' => ['to parameter is not a valid address. please check documentation.'],
        ]);
    });

    $this->postJson('/v3/test-validation')
        ->assertBadRequest()
        ->assertJson([
            'message' => 'to parameter is not a valid address. please check documentation.',
        ]);
});

test('mailgun v3 routes render 429 exceptions as json', function () {
    Route::get('/v3/test-rate-limit', function () {
        abort(Response::HTTP_TOO_MANY_REQUESTS, 'Domain example.com is not allowed to send: account-requests-per-sec limit exceeded, try again after 120 seconds');
    });

    $this->getJson('/v3/test-rate-limit')
        ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
        ->assertJson([
            'message' => 'Domain example.com is not allowed to send: account-requests-per-sec limit exceeded, try again after 120 seconds',
        ]);
});

test('mailgun v3 routes render 500 exceptions as json', function () {
    Route::get('/v3/test-server-error', function () {
        throw new RuntimeException('Boom');
    });

    $this->getJson('/v3/test-server-error')
        ->assertServerError()
        ->assertJson([
            'message' => 'Internal Server Error',
        ]);
});
