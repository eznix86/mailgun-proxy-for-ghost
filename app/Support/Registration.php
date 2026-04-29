<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

class Registration
{
    public static function available(): bool
    {
        return User::query()->doesntExist();
    }
}
