<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_request_delivery_events', function (Blueprint $table): void {
            $table->string('provider_event_id')->nullable()->after('provider_event');
            $table->unique(['newsletter_request_delivery_id', 'provider_event_id'], 'delivery_event_provider_event_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_request_delivery_events', function (Blueprint $table): void {
            $table->dropUnique('delivery_event_provider_event_id_unique');
            $table->dropColumn('provider_event_id');
        });
    }
};
