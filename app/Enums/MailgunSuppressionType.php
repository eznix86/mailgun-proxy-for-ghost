<?php

declare(strict_types=1);

namespace App\Enums;

enum MailgunSuppressionType: string
{
    case Bounces = 'bounces';
    case Complaints = 'complaints';
    case Unsubscribes = 'unsubscribes';

    /**
     * The Mailgun-faithful body message returned when the given suppression is removed.
     */
    public function removalMessage(): string
    {
        return match ($this) {
            self::Bounces => 'Bounce has been removed',
            self::Complaints => 'Spam complaint has been removed',
            self::Unsubscribes => 'Unsubscribe event has been removed',
        };
    }
}
