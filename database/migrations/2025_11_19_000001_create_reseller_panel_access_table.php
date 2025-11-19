<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('reseller_panel_access')) {
            Schema::create('reseller_panel_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reseller_id')->constrained('resellers')->onDelete('cascade');
                $table->foreignId('panel_id')->constrained('panels')->onDelete('cascade');
                $table->json('allowed_node_ids')->nullable();     // for Eylandoo-like panels
                $table->json('allowed_service_ids')->nullable();  // for Marzneshin-like panels
                $table->timestamps();

                $table->unique(['reseller_id', 'panel_id'], 'reseller_panel_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_panel_access');
    }
};
