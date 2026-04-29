<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterRequestDeliveryEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NewsletterRequestDelivery::class, 'newsletter_request_delivery_id');
    }
}
