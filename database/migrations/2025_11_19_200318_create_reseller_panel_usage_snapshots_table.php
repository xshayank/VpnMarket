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
        Schema::create('reseller_panel_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('panel_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('total_usage_bytes')->default(0);
            $table->bigInteger('active_config_count')->default(0);
            $table->timestamp('captured_at');
            $table->unique(['reseller_id', 'panel_id'], 'reseller_panel_usage_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_panel_usage_snapshots');
    }
};
