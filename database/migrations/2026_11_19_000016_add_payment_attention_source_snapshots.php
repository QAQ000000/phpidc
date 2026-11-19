<?php

declare(strict_types=1);

use App\Actions\BackfillPaymentAttentionCasesAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reconciliation_cases', 'source_snapshot')) {
            Schema::table('reconciliation_cases', fn (Blueprint $table) => $table->json('source_snapshot')->nullable());
        }

        // Run with old writers stopped. Success means every legacy attention has a business blocker.
        app(BackfillPaymentAttentionCasesAction::class)->callbacks();
    }

    public function down(): void
    {
        Schema::table('reconciliation_cases', fn (Blueprint $table) => $table->dropColumn('source_snapshot'));
    }
};
