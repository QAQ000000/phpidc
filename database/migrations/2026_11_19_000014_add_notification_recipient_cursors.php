<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('recipient_cursor')->default(0);
            $table->unsignedBigInteger('recipient_upper_bound')->nullable();
            $table->unsignedBigInteger('recipient_binding_cursor')->default(0);
            $table->unsignedBigInteger('recipient_binding_upper_bound')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_events', fn (Blueprint $table) => $table->dropColumn([
            'recipient_cursor', 'recipient_upper_bound', 'recipient_binding_cursor', 'recipient_binding_upper_bound',
        ]));
    }
};
