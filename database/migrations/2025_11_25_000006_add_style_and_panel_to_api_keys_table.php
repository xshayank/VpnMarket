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
            $table->string('api_style')->default('falco')->after('revoked');
            $table->foreignId('default_panel_id')
                ->nullable()
                ->after('api_style')
                ->constrained('panels')
                ->nullOnDelete();

            $table->index(['api_style', 'default_panel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex('api_keys_api_style_default_panel_id_index');
            $table->dropConstrainedForeignId('default_panel_id');
            $table->dropColumn('api_style');
        });
    }
};
