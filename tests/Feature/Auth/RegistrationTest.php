<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered when no users exist', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen redirects to login when a user already exists', function () {
    User::factory()->create();

    $response = $this->get(route('register'));

    $response->assertRedirect(route('login'));
});

test('first user can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('additional users can not register', function () {
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertForbidden();
    expect(User::query()->count())->toBe(1);
});
