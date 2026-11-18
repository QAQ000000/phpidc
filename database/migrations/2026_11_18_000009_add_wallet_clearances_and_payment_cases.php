<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_callback_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('source_type', 32);
            $table->string('source_id', 64);
            $table->char('source_fingerprint', 64);
            $table->string('initial_reason', 64);
            $table->string('status', 32)->default('open');
            $table->foreignId('source_credit_transaction_id')->nullable()->unique()->constrained('financial_transactions')->restrictOnDelete();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'reconciliation_source_unique');
            $table->index(['user_id', 'status', 'id'], 'reconciliation_owner_status_index');
        });
        Schema::create('wallet_clearances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('request_id', 26);
            $table->string('scenario', 32);
            $table->decimal('amount', 12, 2)->unsigned();
            $table->char('currency', 3)->default('CNY');
            $table->foreignId('reconciliation_case_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_credit_transaction_id')->nullable()->constrained('financial_transactions')->restrictOnDelete();
            $table->string('payout_method', 32);
            $table->text('payout_details');
            $table->text('payout_evidence')->nullable();
            $table->text('operation_requests')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('payout_intent_key', 64)->nullable()->unique();
            $table->string('external_reference', 128)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id'], 'wallet_clearance_request_unique');
            $table->index(['user_id', 'status', 'id'], 'wallet_clearance_owner_status_index');
            $table->index(['reconciliation_case_id', 'status', 'id'], 'wallet_clearance_source_status_index');
        });
        Schema::create('external_payout_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('payout_method', 32);
            $table->string('external_reference', 128);
            $table->decimal('amount', 12, 2)->unsigned();
            $table->char('currency', 3)->default('CNY');
            $table->foreignId('affiliate_withdrawal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_clearance_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['payout_method', 'external_reference'], 'external_payout_receipt_unique');
        });
        // SQLite cannot ALTER TABLE ADD CHECK; triggers provide the same database boundary.
        $invalid = '(NEW.affiliate_withdrawal_id IS NULL AND NEW.wallet_clearance_id IS NULL) OR (NEW.affiliate_withdrawal_id IS NOT NULL AND NEW.wallet_clearance_id IS NOT NULL)';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER external_payout_receipt_owner_'.strtolower($operation).' BEFORE '.$operation.' ON external_payout_receipts WHEN '.$invalid." BEGIN SELECT RAISE(ABORT, 'exactly one payout owner required'); END");
            }
        } else {
            DB::statement('ALTER TABLE external_payout_receipts ADD CONSTRAINT external_payout_receipt_one_owner CHECK ((affiliate_withdrawal_id IS NULL) <> (wallet_clearance_id IS NULL))');
        }
        Schema::create('reconciliation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('reconciliation_cases')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('request_key', 128);
            $table->string('action', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->text('evidence')->nullable();
            $table->foreignId('source_credit_transaction_id')->nullable()->constrained('financial_transactions')->restrictOnDelete();
            $table->foreignId('wallet_clearance_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['case_id', 'sequence'], 'reconciliation_entry_sequence_unique');
            $table->unique(['case_id', 'request_key'], 'reconciliation_entry_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_entries');
        Schema::dropIfExists('external_payout_receipts');
        Schema::dropIfExists('wallet_clearances');
        Schema::dropIfExists('reconciliation_cases');
    }
};
