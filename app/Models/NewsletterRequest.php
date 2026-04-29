<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\NewsletterRequestStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewsletterRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'original_request' => 'array',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NewsletterRequestAttempt::class);
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(NewsletterRequestAttempt::class)->latestOfMany();
    }

    public function deliveries(): HasManyThrough
    {
        return $this->hasManyThrough(
            NewsletterRequestDelivery::class,
            NewsletterRequestAttempt::class,
        );
    }

    protected function status(): Attribute
    {
        return Attribute::get(function (): NewsletterRequestStatus {
            $latestAttempt = $this->latestAttempt;

            if ($latestAttempt === null) {
                return NewsletterRequestStatus::Pending;
            }

            if ($latestAttempt->finished_at === null) {
                return NewsletterRequestStatus::Processing;
            }

             if ($latestAttempt->deliveries()->whereIn('latest_event', ['failed', 'rejected'])->exists()) {
                return NewsletterRequestStatus::Failed;
            }

            return $latestAttempt->error_message === null
                ? NewsletterRequestStatus::Processed
                : NewsletterRequestStatus::Failed;
        });
    }
}
