<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds username_prefix and panel_username columns to reseller_configs table
     * to support enhanced username handling for panel interactions.
     */
    public function up(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            // Store the original requested username prefix (for display to users)
            $table->string('username_prefix', 50)->nullable()->after('external_username');
            
            // Store the actual panel username (the generated unique value sent to panel)
            $table->string('panel_username', 50)->nullable()->after('username_prefix');
            
            // Add index for faster prefix-based lookups
            $table->index('username_prefix', 'idx_reseller_configs_username_prefix');
            
            // Add index for panel_username for uniqueness checks
            $table->index('panel_username', 'idx_reseller_configs_panel_username');
        });

        // Migrate existing data: extract prefix from external_username for legacy records
        // For records where panel_username is null, the external_username IS the panel username
        // We'll populate panel_username with external_username value
        \Illuminate\Support\Facades\DB::statement('
            UPDATE reseller_configs 
            SET panel_username = external_username 
            WHERE panel_username IS NULL AND external_username IS NOT NULL
        ');

        // Extract prefix from existing usernames using PHP for database-agnostic migration
        // This handles legacy usernames like "user_123_cfg_456" -> prefix "user_123_cfg"
        // and new-style names like "ali_abc123" -> prefix "ali"
        $configs = \Illuminate\Support\Facades\DB::table('reseller_configs')
            ->whereNull('username_prefix')
            ->whereNotNull('external_username')
            ->select('id', 'external_username')
            ->get();

        foreach ($configs as $config) {
            $username = $config->external_username;
            // Extract prefix: everything before the last underscore
            $lastUnderscorePos = strrpos($username, '_');
            $prefix = $lastUnderscorePos !== false 
                ? substr($username, 0, $lastUnderscorePos) 
                : $username;
            
            \Illuminate\Support\Facades\DB::table('reseller_configs')
                ->where('id', $config->id)
                ->update(['username_prefix' => $prefix]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            $table->dropIndex('idx_reseller_configs_username_prefix');
            $table->dropIndex('idx_reseller_configs_panel_username');
            $table->dropColumn(['username_prefix', 'panel_username']);
        });
    }
};
