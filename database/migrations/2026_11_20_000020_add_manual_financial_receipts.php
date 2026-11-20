<?php

use App\Support\ExternalCollectionReceipts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check historical receipt uniqueness before MySQL DDL can commit new schema.
        app(ExternalCollectionReceipts::class)->validateLegacy();
        if (! Schema::hasTable('offline_invoice_receipts')) {
            Schema::create('offline_invoice_receipts', function (Blueprint $table): void {
                $table->id();
                $table->char('request_id', 26)->unique();
                $table->foreignId('invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
                $table->decimal('amount', 12, 2)->unsigned();
                $table->char('currency', 3);
                $table->string('external_channel', 32);
                $table->string('external_reference', 128);
                $table->timestamp('received_at');
                $table->string('reason', 255);
                $table->text('encrypted_evidence')->nullable();
                $table->json('intent');
                $table->json('allocation_snapshot');
                $table->foreignId('payment_transaction_id')->unique('offline_receipt_payment_unique')->constrained('financial_transactions')->restrictOnDelete();
                $table->foreignId('settlement_transaction_id')->unique('offline_receipt_settle_unique')->constrained('financial_transactions')->restrictOnDelete();
                $table->foreignId('fulfillment_task_id')->unique()->constrained('fulfillment_tasks')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['external_channel', 'external_reference'], 'offline_receipt_external_unique');
            });
        }
        if (! Schema::hasTable('external_collection_receipts')) {
            Schema::create('external_collection_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('offline_invoice_receipt_id')->nullable()->unique('collection_offline_unique')->constrained('offline_invoice_receipts')->restrictOnDelete();
                $table->foreignId('receivable_settlement_id')->nullable()->unique('collection_receivable_unique')->constrained('affiliate_receivable_settlements')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
                $table->char('active_receipt_key', 64)->nullable()->unique('collection_active_receipt_unique');
                $table->string('external_channel', 32);
                $table->string('external_reference', 128);
                $table->decimal('amount', 12, 2)->unsigned();
                $table->char('currency', 3);
                $table->timestamp('released_at')->nullable();
                $table->timestamp('created_at');
                $table->index(['external_channel', 'external_reference'], 'collection_reference_lookup');
            });
        }
        if (! Schema::hasIndex('external_collection_receipts', 'collection_reference_lookup')) {
            Schema::table('external_collection_receipts', fn (Blueprint $table) => $table->index(['external_channel', 'external_reference'], 'collection_reference_lookup'));
        }
        app(ExternalCollectionReceipts::class)->backfillLegacy();
    }

    public function down(): void
    {
        Schema::dropIfExists('external_collection_receipts');
        Schema::dropIfExists('offline_invoice_receipts');
    }
};
