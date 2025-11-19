<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            
            // Add unique index on external_username if it doesn't exist
            // Check if the column can be made unique (no duplicates exist)
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexesFound = $sm->listTableIndexes('reseller_configs');
            $hasUniqueIndex = false;
            
            foreach ($indexesFound as $index) {
                if ($index->isUnique() && in_array('external_username', $index->getColumns())) {
                    $hasUniqueIndex = true;
                    break;
                }
            }
            
            if (!$hasUniqueIndex) {
                try {
                    $table->unique('external_username', 'reseller_configs_external_username_unique');
                } catch (\Exception $e) {
                    // If there are duplicates, we can't add the unique constraint
                    // Log the error but don't fail the migration
                    \Illuminate\Support\Facades\Log::warning('Could not add unique constraint to external_username: ' . $e->getMessage());
                }
            }
        });
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
