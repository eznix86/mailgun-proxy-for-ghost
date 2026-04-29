<?php

declare(strict_types=1);

use App\Models\NewsletterRequestDelivery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_request_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(NewsletterRequestDelivery::class)->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('provider_event')->nullable();
            $table->string('severity')->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_request_delivery_events');
    }
};
