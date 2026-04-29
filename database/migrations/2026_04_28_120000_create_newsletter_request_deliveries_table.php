<?php

declare(strict_types=1);

use App\Models\NewsletterRequestAttempt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_request_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(NewsletterRequestAttempt::class)->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('provider');
            $table->string('recipient');
            $table->string('mailgun_message_id')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('from');
            $table->string('subject')->default('');
            $table->string('latest_event')->nullable();
            $table->string('latest_severity')->nullable();
            $table->timestamp('latest_event_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('tags')->nullable();
            $table->json('user_variables')->nullable();
            $table->json('recipient_variables')->nullable();
            $table->timestamps();

            $table->index(['domain', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_request_deliveries');
    }
};
