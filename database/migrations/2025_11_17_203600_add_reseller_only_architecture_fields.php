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
        // Update resellers table
        Schema::table('resellers', function (Blueprint $table) {
            // Add primary_panel_id if it doesn't exist (panel_id might already exist)
            if (!Schema::hasColumn('resellers', 'primary_panel_id') && Schema::hasColumn('resellers', 'panel_id')) {
                // Rename panel_id to primary_panel_id for clarity
                $table->renameColumn('panel_id', 'primary_panel_id');
            } elseif (!Schema::hasColumn('resellers', 'primary_panel_id')) {
                $table->foreignId('primary_panel_id')->nullable()->after('config_limit')->constrained('panels')->onDelete('set null');
            }

            // Add meta JSON column for storing allowed node/service IDs
            if (!Schema::hasColumn('resellers', 'meta')) {
                $table->json('meta')->nullable()->after('settings');
            }

            // Add max_configs if it doesn't exist (might be added by config_limit)
            if (!Schema::hasColumn('resellers', 'max_configs')) {
                $table->integer('max_configs')->nullable()->after('config_limit');
            }
        });

        // Update reseller status enum to include new suspension types
        $driver = Schema::connection(null)->getConnection()->getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE resellers MODIFY COLUMN status ENUM('active', 'disabled', 'suspended', 'suspended_wallet', 'suspended_traffic', 'suspended_other') DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TYPE reseller_status ADD VALUE IF NOT EXISTS 'disabled'");
            DB::statement("ALTER TYPE reseller_status ADD VALUE IF NOT EXISTS 'suspended_traffic'");
            DB::statement("ALTER TYPE reseller_status ADD VALUE IF NOT EXISTS 'suspended_other'");
        }
        // SQLite: No action needed - it stores enums as strings

        // Add indexes for better query performance
        Schema::table('resellers', function (Blueprint $table) {
            // For SQLite we can just try to add the indexes, they'll be skipped if they exist
            try {
                $table->index(['type', 'status'], 'resellers_type_status_index');
            } catch (\Exception $e) {
                // Index might already exist, continue
            }
            
            try {
                $table->index('primary_panel_id', 'resellers_primary_panel_id_index');
            } catch (\Exception $e) {
                // Index might already exist, continue
            }
        });

        // Update panels table with registration defaults
        Schema::table('panels', function (Blueprint $table) {
            if (!Schema::hasColumn('panels', 'registration_default_node_ids')) {
                $table->json('registration_default_node_ids')->nullable()->after('extra');
            }
            if (!Schema::hasColumn('panels', 'registration_default_service_ids')) {
                $table->json('registration_default_service_ids')->nullable()->after('registration_default_node_ids');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            try {
                $table->dropIndex('resellers_type_status_index');
            } catch (\Exception $e) {
                // Index might not exist
            }
            
            try {
                $table->dropIndex('resellers_primary_panel_id_index');
            } catch (\Exception $e) {
                // Index might not exist
            }
            
            if (Schema::hasColumn('resellers', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('resellers', 'max_configs')) {
                $table->dropColumn('max_configs');
            }
        });

        Schema::table('panels', function (Blueprint $table) {
            if (Schema::hasColumn('panels', 'registration_default_node_ids')) {
                $table->dropColumn('registration_default_node_ids');
            }
            if (Schema::hasColumn('panels', 'registration_default_service_ids')) {
                $table->dropColumn('registration_default_service_ids');
            }
        });

        // Note: Reverting enum changes in MySQL is complex and may cause data loss
        // Admin should backup before running down migration
    }
};
