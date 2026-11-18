<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_mapping_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_product_id')->constrained('products')->restrictOnDelete();
            $table->string('kind', 32)->default('mapping');
            $table->char('request_id', 26);
            $table->char('review_fingerprint', 64);
            $table->json('snapshot');
            $table->string('reason');
            $table->timestamp('checked_at');
            $table->timestamp('confirmed_at');
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->unique(['actor_id', 'request_id'], 'instance_mapping_review_request_unique');
            $table->index(['instance_id', 'kind', 'id'], 'instance_mapping_review_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_mapping_reviews');
    }
};
