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
        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid('api_key_id')->nullable();
            $table->string('action'); // e.g., configs.create, configs.update
            $table->string('target_type')->nullable(); // e.g., config, panel
            $table->string('target_id_or_name')->nullable(); // ID or name of the target
            $table->json('request_metadata')->nullable(); // IP, user-agent, body fingerprint
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['api_key_id', 'created_at']);
            $table->index('action');

            $table->foreign('api_key_id')->references('id')->on('api_keys')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
    }
};
