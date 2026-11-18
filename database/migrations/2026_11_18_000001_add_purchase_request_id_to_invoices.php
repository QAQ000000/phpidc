<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            // C22 / INV-K5: legacy and non-purchase invoices keep NULL.
            $table->string('purchase_request_id', 26)->nullable();
            $table->unique(['user_id', 'purchase_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'purchase_request_id']);
            $table->dropColumn('purchase_request_id');
        });
    }
};
