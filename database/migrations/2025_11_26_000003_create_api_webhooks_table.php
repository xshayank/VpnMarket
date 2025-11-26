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
        Schema::create('api_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid('api_key_id')->nullable();
            $table->string('name', 100);
            $table->string('url');
            $table->string('secret', 64)->nullable(); // For webhook signature verification
            $table->json('events'); // Array of event types to listen for
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            
            $table->foreign('api_key_id')->references('id')->on('api_keys')->onDelete('cascade');
            $table->index(['user_id', 'is_active']);
            $table->index('api_key_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_webhooks');
    }
};
