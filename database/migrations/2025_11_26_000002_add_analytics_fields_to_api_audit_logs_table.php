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
        Schema::table('api_audit_logs', function (Blueprint $table) {
            // Add more detailed logging fields
            $table->string('api_style', 20)->nullable()->after('action');
            $table->string('endpoint')->nullable()->after('api_style');
            $table->string('http_method', 10)->nullable()->after('endpoint');
            $table->unsignedSmallInteger('response_status')->nullable()->after('http_method');
            $table->unsignedInteger('response_time_ms')->nullable()->after('response_status');
            $table->boolean('rate_limited')->default(false)->after('response_time_ms');
            $table->json('error_details')->nullable()->after('rate_limited');
            
            // Add indexes for analytics
            $table->index(['api_style', 'created_at']);
            $table->index(['endpoint', 'created_at']);
            $table->index('response_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['api_style', 'created_at']);
            $table->dropIndex(['endpoint', 'created_at']);
            $table->dropIndex(['response_status']);
            $table->dropColumn([
                'api_style',
                'endpoint',
                'http_method',
                'response_status',
                'response_time_ms',
                'rate_limited',
                'error_details',
            ]);
        });
    }
};
