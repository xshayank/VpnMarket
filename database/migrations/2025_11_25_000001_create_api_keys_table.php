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
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Friendly name for the key
            $table->string('key_hash'); // HMAC-SHA256 hash of the actual key
            $table->json('scopes'); // List of allowed scopes: configs:create, configs:read, etc.
            $table->json('ip_whitelist')->nullable(); // Optional IP whitelist
            $table->timestamp('expires_at')->nullable(); // Optional expiration
            $table->timestamp('last_used_at')->nullable(); // Track last usage
            $table->boolean('revoked')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'revoked']);
            $table->index('key_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
