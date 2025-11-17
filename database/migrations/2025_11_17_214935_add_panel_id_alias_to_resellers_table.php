<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add panel_id as a backward-compatible alias column for primary_panel_id.
     * This ensures legacy code referencing panel_id continues to work while
     * we transition to using primary_panel_id as the canonical field.
     */
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Only add panel_id if it doesn't exist and primary_panel_id does exist
            if (!Schema::hasColumn('resellers', 'panel_id') && Schema::hasColumn('resellers', 'primary_panel_id')) {
                $table->unsignedBigInteger('panel_id')->nullable()->after('username_prefix');
                $table->index('panel_id');
                
                // Add foreign key constraint
                $table->foreign('panel_id')->references('id')->on('panels')->onDelete('set null');
            }
        });

        // Backfill panel_id from primary_panel_id for existing records
        DB::table('resellers')
            ->whereNull('panel_id')
            ->whereNotNull('primary_panel_id')
            ->update(['panel_id' => DB::raw('primary_panel_id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            if (Schema::hasColumn('resellers', 'panel_id')) {
                $table->dropForeign(['panel_id']);
                $table->dropIndex(['panel_id']);
                $table->dropColumn('panel_id');
            }
        });
    }
};
