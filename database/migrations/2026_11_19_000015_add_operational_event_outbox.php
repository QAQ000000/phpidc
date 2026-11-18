<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('event_key', 191)->unique();
            // Only allowlisted nonsecret source IDs and status are retained here.
            $table->json('variables');
            $table->boolean('notification_suppressed')->default(false);
            $table->foreignId('notification_event_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('observed_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['notified_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_events');
    }
};
