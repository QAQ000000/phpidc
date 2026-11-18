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
            $table->foreignId('renewal_root_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->index('renewal_root_id');
            $table->foreignId('replaces_invoice_id')->nullable()->unique()->constrained('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['replaces_invoice_id']);
            $table->dropForeign(['renewal_root_id']);
            $table->dropUnique(['replaces_invoice_id']);
            $table->dropIndex(['renewal_root_id']);
            $table->dropColumn(['replaces_invoice_id', 'renewal_root_id']);
        });
    }
};
