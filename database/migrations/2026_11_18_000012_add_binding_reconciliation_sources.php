<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_upstream_bindings', function (Blueprint $table): void {
            // NULL means that historical endpoint identity was never captured. Never infer it from current account settings.
            $table->json('source_snapshot')->nullable();
        });
        Schema::table('reconciliation_cases', function (Blueprint $table): void {
            $table->foreignId('instance_upstream_binding_id')->nullable()->unique('reconciliation_binding_unique')
                ->constrained('instance_upstream_bindings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_cases', function (Blueprint $table): void {
            $table->dropForeign(['instance_upstream_binding_id']);
            $table->dropUnique('reconciliation_binding_unique');
            $table->dropColumn('instance_upstream_binding_id');
        });
        Schema::table('instance_upstream_bindings', fn (Blueprint $table) => $table->dropColumn('source_snapshot'));
    }
};
