<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

test('users can be mass assigned through the global unguarded model setup', function () {
    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    expect($user->name)->toBe('Test User')
        ->and($user->email)->toBe('test@example.com')
        ->and($user->exists)->toBeTrue();
});

test('the user morph alias is enforced', function () {
    expect(Relation::getMorphedModel('user'))->toBe(User::class);
});
