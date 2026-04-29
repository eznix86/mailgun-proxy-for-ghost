<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterRequestDelivery extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'latest_event_at' => 'datetime',
            'tags' => 'array',
            'user_variables' => 'array',
            'recipient_variables' => 'array',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(NewsletterRequestAttempt::class, 'newsletter_request_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NewsletterRequestDeliveryEvent::class);
    }
}
