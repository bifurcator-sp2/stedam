<?php
// database/migrations/xxxx_create_file_sets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_sets', function (Blueprint $table) {
            $table->id();

            $table->string('model');
            $table->unsignedBigInteger('model_id');

            // ENUM для purpose
            $table->enum('purpose', [
                'avatar',
                'cover',
                'background-image',
            ])->default('cover');

            $table->json('images')->nullable();
            $table->json('files')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['model', 'model_id']);
            $table->unique(['model', 'model_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_sets');
    }
};
