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
        Schema::table('api_keys', function (Blueprint $table) {
            // API style: 'falco' (native) or 'marzneshin' (compatibility mode)
            $table->string('api_style', 20)->default('falco')->after('scopes');
            
            // Default panel for Marzneshin-style API keys
            $table->foreignId('default_panel_id')
                ->nullable()
                ->after('api_style')
                ->constrained('panels')
                ->onDelete('set null');
            
            // Rate limiting: requests per minute
            $table->unsignedInteger('rate_limit_per_minute')->default(60)->after('default_panel_id');
            
            // Request counter for rate limiting
            $table->unsignedInteger('requests_this_minute')->default(0)->after('rate_limit_per_minute');
            $table->timestamp('rate_limit_reset_at')->nullable()->after('requests_this_minute');
            
            // Add index for api_style queries
            $table->index('api_style');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex(['api_style']);
            $table->dropForeign(['default_panel_id']);
            $table->dropColumn([
                'api_style',
                'default_panel_id',
                'rate_limit_per_minute',
                'requests_this_minute',
                'rate_limit_reset_at',
            ]);
        });
    }
};
