<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 50)->nullable();
            $table->enum('status', ['success', 'failed']);
            $table->text('error')->nullable();
            $table->json('row_data');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_import_logs');
    }
};
