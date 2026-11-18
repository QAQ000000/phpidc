<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('affiliate_frozen_balance', 12, 2)->unsigned()->default('0.00');
        });
        Schema::table('affiliate_withdrawals', function (Blueprint $table): void {
            $table->string('payout_method', 32)->nullable();
            $table->text('encrypted_payout_details')->nullable();
            $table->text('encrypted_payout_evidence')->nullable();
            $table->text('operation_requests')->nullable();
            $table->string('payout_intent_key', 64)->nullable()->unique();
            $table->string('external_reference', 128)->nullable();
            $table->unique(['payout_method', 'external_reference'], 'affiliate_payout_external_reference_unique');
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_withdrawals', function (Blueprint $table): void {
            $table->dropForeign(['started_by']);
            $table->dropForeign(['confirmed_by']);
            $table->dropUnique('affiliate_payout_external_reference_unique');
            $table->dropUnique(['payout_intent_key']);
            $table->dropColumn(['payout_method', 'encrypted_payout_details', 'encrypted_payout_evidence', 'operation_requests',
                'payout_intent_key', 'external_reference', 'started_by', 'confirmed_by', 'started_at', 'completed_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('affiliate_frozen_balance'));
    }
};
