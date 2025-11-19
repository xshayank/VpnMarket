<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table) {
            if (!Schema::hasColumn('panels', 'auto_assign_to_resellers')) {
                $table->boolean('auto_assign_to_resellers')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('panels', function (Blueprint $table) {
            if (Schema::hasColumn('panels', 'auto_assign_to_resellers')) {
                $table->dropColumn('auto_assign_to_resellers');
            }
        });
    }
};
