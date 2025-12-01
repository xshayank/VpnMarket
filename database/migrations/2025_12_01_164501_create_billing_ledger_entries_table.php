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
        Schema::create('billing_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->onDelete('cascade');
            $table->foreignId('reseller_config_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type'); // 'reset_traffic', 'delete_config', 'hourly_charge'
            $table->bigInteger('charged_bytes');
            $table->integer('amount_charged'); // Cost in Toman
            $table->integer('price_per_gb');
            $table->integer('wallet_balance_before');
            $table->integer('wallet_balance_after');
            $table->json('meta')->nullable(); // Additional context
            $table->timestamps();

            $table->index(['reseller_id', 'created_at']);
            $table->index(['reseller_config_id', 'action_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_ledger_entries');
    }
};
