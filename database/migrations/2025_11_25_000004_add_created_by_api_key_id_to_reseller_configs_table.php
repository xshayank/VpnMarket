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
            $table->uuid('created_by_api_key_id')->nullable()->after('meta');
            
            $table->foreign('created_by_api_key_id')
                ->references('id')
                ->on('api_keys')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_configs', function (Blueprint $table) {
            $table->dropForeign(['created_by_api_key_id']);
            $table->dropColumn('created_by_api_key_id');
        });
    }
};
