<?php

declare(strict_types=1);

test('home redirects to registration', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('register'));
});
