<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterRequestAttempt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function newsletterRequest(): BelongsTo
    {
        return $this->belongsTo(NewsletterRequest::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterRequestDelivery::class);
    }
}
