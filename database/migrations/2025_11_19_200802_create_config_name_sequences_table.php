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
        Schema::create('config_name_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->onDelete('cascade');
            $table->foreignId('panel_id')->constrained('panels')->onDelete('cascade');
            $table->unsignedInteger('next_seq')->default(1);
            $table->timestamps();

            // Ensure unique sequence per reseller-panel combination
            $table->unique(['reseller_id', 'panel_id'], 'config_seq_reseller_panel_unique');
            
            // Add indexes for better query performance
            $table->index('reseller_id');
            $table->index('panel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_name_sequences');
    }
};
