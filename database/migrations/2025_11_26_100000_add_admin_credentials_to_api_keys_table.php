<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds admin_username and admin_password columns for Marzneshin-style API keys.
     * These credentials enable a dedicated admin-style authentication flow where
     * the bot posts admin credentials to /api/admins/token instead of using
     * the plaintext API key directly.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Admin username for Marzneshin-style authentication (e.g. 'mz_XXXXXXXXXX')
            $table->string('admin_username', 50)->nullable()->after('default_panel_id');

            // Hashed admin password for Marzneshin-style authentication
            $table->string('admin_password')->nullable()->after('admin_username');

            // Index for admin username lookups during authentication
            $table->index('admin_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex(['admin_username']);
            $table->dropColumn(['admin_username', 'admin_password']);
        });
    }
};
