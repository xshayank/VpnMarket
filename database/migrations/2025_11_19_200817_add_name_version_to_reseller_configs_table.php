<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            // Add name_version column if it doesn't exist
            if (!Schema::hasColumn('reseller_configs', 'name_version')) {
                $table->tinyInteger('name_version')->nullable()->after('external_username');
            }
        });
        
        // Try to add unique index on external_username if it doesn't exist
        // We'll use a raw query approach that's compatible with SQLite
        try {
            $driver = Schema::connection(null)->getConnection()->getDriverName();
            
            if ($driver === 'sqlite') {
                // For SQLite, check if the index exists via PRAGMA
                $indexes = DB::select('PRAGMA index_list(reseller_configs)');
                $hasUniqueIndex = collect($indexes)->contains(function ($index) {
                    return $index->unique == 1 && str_contains($index->name, 'external_username');
                });
                
                if (!$hasUniqueIndex) {
                    // Check for duplicates before adding unique constraint
                    $duplicates = DB::table('reseller_configs')
                        ->select('external_username', DB::raw('COUNT(*) as count'))
                        ->groupBy('external_username')
                        ->having('count', '>', 1)
                        ->count();
                    
                    if ($duplicates === 0) {
                        Schema::table('reseller_configs', function (Blueprint $table) {
                            $table->unique('external_username', 'reseller_configs_external_username_unique');
                        });
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Cannot add unique constraint to external_username: duplicates exist');
                    }
                }
            } else {
                // For MySQL/PostgreSQL
                Schema::table('reseller_configs', function (Blueprint $table) {
                    $table->unique('external_username', 'reseller_configs_external_username_unique');
                });
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the migration
            \Illuminate\Support\Facades\Log::warning('Could not add unique constraint to external_username: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            if (Schema::hasColumn('reseller_configs', 'name_version')) {
                $table->dropColumn('name_version');
            }
            
            // We don't drop the unique index on down migration
            // as it might have existed before this migration
        });
    }
};
