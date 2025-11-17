<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // If the resellers table doesn't exist yet, nothing to do.
        if (! Schema::hasTable('resellers')) {
            return;
        }

        // Add the panel_id column if it doesn't exist yet.
        if (! Schema::hasColumn('resellers', 'panel_id')) {
            Schema::table('resellers', function (Blueprint $table) {
                // place after an appropriate column; adjust 'id' if your table differs
                $table->unsignedBigInteger('panel_id')->nullable()->after('id');
            });
        }

        // Add foreign key only if it does not already exist.
        // Use information_schema on MySQL; on other drivers fall back to a safe try/catch.
        $constraintName = 'resellers_panel_id_foreign';
        $driver = DB::getDriverName();

        $fkExists = false;

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                "SELECT CONSTRAINT_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                ['resellers', $constraintName]
            );

            $fkExists = ($row !== null);
        } else {
            // For sqlite or other drivers we cannot rely on information_schema.
            // We'll try to detect using Doctrine if available (best-effort) or skip detection.
            try {
                $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
                $tableDetails = $schemaManager->listTableDetails(Schema::getConnection()->getTablePrefix() . 'resellers');
                $fkExists = $tableDetails->hasForeignKey($constraintName);
            } catch (\Throwable $e) {
                // If detection fails, we'll conservatively attempt to add the FK in a try/catch below.
                $fkExists = false;
            }
        }

        if (! $fkExists) {
            try {
                Schema::table('resellers', function (Blueprint $table) use ($constraintName) {
                    $table->foreign('panel_id', $constraintName)
                        ->references('id')
                        ->on('panels')
                        ->onDelete('set null');
                });
            } catch (\Throwable $e) {
                // If adding the FK fails (race-condition, existing different-named FK), log and continue.
                // In migrations we can't directly call logger(), so rethrow only if it's a fatal unexpected exception.
                // Best effort: swallow known duplicate constraint errors, rethrow others.
                $msg = $e->getMessage();
                if (stripos($msg, 'Duplicate foreign key') !== false
                    || stripos($msg, 'Duplicate key name') !== false
                    || stripos($msg, 'Duplicate foreign key constraint name') !== false
                ) {
                    // expected in some environments; skip
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('resellers')) {
            return;
        }

        $constraintName = 'resellers_panel_id_foreign';
        $driver = DB::getDriverName();

        // Drop foreign key if it exists
        $fkExists = false;
        if ($driver === 'mysql') {
            $row = DB::selectOne(
                "SELECT CONSTRAINT_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                ['resellers', $constraintName]
            );

            $fkExists = ($row !== null);
        } else {
            try {
                $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
                $tableDetails = $schemaManager->listTableDetails(Schema::getConnection()->getTablePrefix() . 'resellers');
                $fkExists = $tableDetails->hasForeignKey($constraintName);
            } catch (\Throwable $e) {
                $fkExists = false;
            }
        }

        if ($fkExists) {
            Schema::table('resellers', function (Blueprint $table) use ($constraintName) {
                // dropForeign accepts the constraint name
                $table->dropForeign($constraintName);
            });
        } else {
            // As a fallback, attempt to drop a foreign by column (if exists)
            try {
                Schema::table('resellers', function (Blueprint $table) {
                    if (Schema::hasColumn('resellers', 'panel_id')) {
                        // This will succeed only if a foreign keyed by convention exists
                        $table->dropForeign(['panel_id']);
                    }
                });
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Drop the column if it exists
        if (Schema::hasColumn('resellers', 'panel_id')) {
            Schema::table('resellers', function (Blueprint $table) {
                $table->dropColumn('panel_id');
            });
        }
    }
};
