<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_type_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('block_type_id')
                ->constrained('block_types')
                ->cascadeOnDelete();

            $table->string('locale', 10)->index();
            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['block_type_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_type_translations');
    }
};
