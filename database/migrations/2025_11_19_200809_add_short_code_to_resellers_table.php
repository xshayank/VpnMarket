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
        Schema::table('resellers', function (Blueprint $table) {
            // Add short_code column if it doesn't exist
            if (!Schema::hasColumn('resellers', 'short_code')) {
                $table->string('short_code', 8)->nullable()->after('user_id');
                $table->index('short_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            if (Schema::hasColumn('resellers', 'short_code')) {
                $table->dropIndex(['short_code']);
                $table->dropColumn('short_code');
            }
        });
    }
};
