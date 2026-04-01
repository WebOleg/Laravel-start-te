<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_generation_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_file');                     // original upload filename
            $table->string('s3_path_source')->nullable();      // S3 path of the source file
            $table->string('s3_path_result')->nullable();      // S3 path of generated output
            $table->string('status')->default('queued');       // queued|processing|completed|failed
            $table->decimal('target_amount', 12, 2);           // e.g. 13000.00
            $table->decimal('tolerance', 12, 2)->default(500); // ±500
            $table->decimal('achieved_amount', 12, 2)->nullable();
            $table->unsignedInteger('total_input_rows')->default(0);
            $table->unsignedInteger('eligible_rows')->default(0);      // after all filters
            $table->unsignedInteger('selected_rows')->default(0);      // included in output
            $table->unsignedInteger('excluded_blacklist_rows')->default(0);
            $table->unsignedInteger('excluded_billing_rows')->default(0);
            $table->unsignedInteger('excluded_previously_used_rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        // Detail table: every record selected for a generated file
        // Used to exclude these IBANs from future generations
        Schema::create('file_generation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')
                ->constrained('file_generation_batches')
                ->cascadeOnDelete();
            $table->string('iban', 34)->index();               // normalised IBAN
            $table->decimal('amount', 8, 2);          // amount from the price ladder
            $table->unsignedInteger('source_row_index');       // row number in source file
            $table->timestamps();

            // Prevent same IBAN being selected twice in the same batch
            $table->unique(['batch_id', 'iban']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_generation_records');
        Schema::dropIfExists('file_generation_batches');
    }
};
