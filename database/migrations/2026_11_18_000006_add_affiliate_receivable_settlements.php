<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_receivable_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('clawback_transaction_id')->constrained('financial_transactions')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2)->unsigned();
            $table->char('currency', 3);
            $table->string('source', 32);
            $table->string('external_channel', 32);
            $table->string('external_reference', 128);
            $table->char('active_receipt_key', 64)->nullable()->unique('receivable_active_receipt_unique');
            $table->char('request_id', 26)->unique('receivable_settlement_request_unique');
            $table->foreignId('ledger_transaction_id')->unique('receivable_settlement_ledger_unique')->constrained('financial_transactions')->restrictOnDelete();
            $table->foreignId('corrects_settlement_id')->nullable()->unique('receivable_correction_parent_unique')->constrained('affiliate_receivable_settlements')->restrictOnDelete();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->timestamp('received_at');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'clawback_transaction_id', 'id'], 'receivable_user_root_index');
            $table->index(['clawback_transaction_id', 'id'], 'receivable_root_index');
            $table->index(['external_channel', 'external_reference'], 'receivable_receipt_history_index');
        });
        Schema::create('affiliate_receivable_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->unique('receivable_reversal_settlement_unique')->constrained('affiliate_receivable_settlements')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('clawback_transaction_id')->constrained('financial_transactions')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2)->unsigned();
            $table->char('currency', 3);
            $table->char('request_id', 26)->unique('receivable_reversal_request_unique');
            $table->foreignId('ledger_transaction_id')->unique('receivable_reversal_ledger_unique')->constrained('financial_transactions')->restrictOnDelete();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->string('correction_reference', 128);
            $table->timestamps();
            $table->index(['user_id', 'clawback_transaction_id'], 'receivable_reversal_user_root_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_receivable_reversals');
        Schema::dropIfExists('affiliate_receivable_settlements');
    }
};
