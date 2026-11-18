<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_entitlement_archives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->unique()->constrained()->restrictOnDelete();
            $table->char('request_id', 26);
            $table->char('delivery_fingerprint', 64);
            $table->json('delivery_snapshot');
            $table->unsignedSmallInteger('confirmation_version')->default(1);
            $table->timestamp('confirmed_at');
            $table->timestamp('archived_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_id'], 'instance_entitlement_archive_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_entitlement_archives');
    }
};
