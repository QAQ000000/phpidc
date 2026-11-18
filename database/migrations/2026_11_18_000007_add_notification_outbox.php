<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope', 16)->default('user');
            $table->string('event_type', 64);
            $table->string('event_key', 191);
            $table->char('dedupe_key', 64)->unique();
            $table->longText('encrypted_payload')->nullable();
            $table->timestamp('expanded_at')->nullable();
            $table->string('suppression_reason', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['scope', 'expanded_at', 'id']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->string('recipient_key', 96);
            $table->unsignedBigInteger('binding_id')->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->string('job_kind', 16)->default('event');
            $table->longText('encrypted_payload')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->uuid('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['notification_event_id', 'channel', 'recipient_key'], 'notification_delivery_recipient_unique');
            $table->index(['status', 'available_at', 'id'], 'notification_delivery_due_index');
            $table->index(['status', 'lease_expires_at', 'id'], 'notification_delivery_lease_index');
            $table->index(['user_id', 'id']);
        });
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreignId('notification_delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_no')->nullable();
            $table->unique(['notification_delivery_id', 'attempt_no'], 'notification_log_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropUnique('notification_log_attempt_unique');
            $table->dropConstrainedForeignId('notification_delivery_id');
            $table->dropColumn('attempt_no');
        });
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_events');
    }
};
