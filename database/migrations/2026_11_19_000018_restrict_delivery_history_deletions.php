<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite must disable FK checks before, rather than inside, its rebuild transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        $this->replaceDeletionRules('restrict');
    }

    public function down(): void
    {
        $this->replaceDeletionRules('cascade');
    }

    private function replaceDeletionRules(string $onDelete): void
    {
        $changes = [];
        foreach (['redemption_codes' => ['product_id', 'products'], 'instance_upstream_bindings' => ['instance_id', 'instances']] as $table => [$column, $parent]) {
            $foreign = collect(Schema::getForeignKeys($table))->where('columns', [$column])->sole();
            if ($foreign['foreign_table'] !== $parent || $foreign['foreign_columns'] !== ['id']) {
                throw new LogicException('Unexpected delivery-history foreign key on '.$table.'.'.$column);
            }
            if (strtolower($foreign['on_delete']) !== $onDelete) {
                $changes[] = [$table, $column, $parent, $foreign];
            }
        }
        if ($changes === []) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            if (DB::transactionLevel() !== 0) {
                throw new LogicException('Delivery-history SQLite rebuild requires an independent migration transaction.');
            }
            $enabled = (bool) DB::scalar('PRAGMA foreign_keys');
            Schema::disableForeignKeyConstraints();
            try {
                DB::transaction(function () use ($changes, $onDelete): void {
                    foreach ($changes as [$table, $column, $parent, $foreign]) {
                        $sequence = DB::table('sqlite_sequence')->where('name', $table)->value('seq');
                        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $foreign, $onDelete): void {
                            $blueprint->dropForeign([$column]);
                            $blueprint->foreign($column)->references('id')->on($parent)
                                ->onDelete($onDelete)->onUpdate($foreign['on_update']);
                        });
                        // Rebuilding must not reuse previously deleted delivery-history IDs.
                        if ($sequence !== null) {
                            DB::table('sqlite_sequence')->where('name', $table)->update(['seq' => $sequence]);
                        }
                    }
                    if (DB::select('PRAGMA foreign_key_check') !== []) {
                        throw new LogicException('Delivery-history foreign key integrity check failed.');
                    }
                });
            } finally {
                if ($enabled) {
                    Schema::enableForeignKeyConstraints();
                }
            }

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Delivery-history migration supports SQLite and MySQL.');
        }
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($changes as [$table, $column, $parent, $foreign]) {
            // A new target constraint name avoids MySQL COPY-algorithm name collisions.
            $name = $table.'_'.$column.($onDelete === 'restrict' ? '_retained_foreign' : '_foreign');
            // One atomic MySQL ALTER per table; completed tables are skipped on retry.
            DB::statement('ALTER TABLE '.$grammar->wrapTable($table)
                .' DROP FOREIGN KEY '.$grammar->wrap($foreign['name'])
                .', ADD CONSTRAINT '.$grammar->wrap($name)
                .' FOREIGN KEY ('.$grammar->wrap($column).') REFERENCES '.$grammar->wrapTable($parent).' ('.$grammar->wrap('id').')'
                .' ON DELETE '.$onDelete.' ON UPDATE '.$foreign['on_update']);
        }
    }
};
