<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->default('emp');
            $table->string('unique_id', 64);
            $table->string('event_type', 32)->nullable();
            $table->string('transaction_type', 32)->nullable();
            $table->string('status', 16)->nullable();
            $table->string('signature', 128)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_type', 32)->nullable();
            $table->tinyInteger('processing_status')->unsigned()->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('payload_size')->nullable();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->tinyInteger('retry_count')->unsigned()->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'unique_id', 'event_type'], 'wh_dedup');
            $table->index(['processing_status', 'created_at'], 'wh_status_date');
            $table->index('created_at', 'wh_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
