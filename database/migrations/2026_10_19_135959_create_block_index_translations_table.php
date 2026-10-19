<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_index_translations', function (Blueprint $table) {
            $table->id();

            // Связь с основной таблицей. При удалении родителя переводы удаляются каскадно.
            $table->foreignId('block_index_id')
                ->constrained('block_indexes')
                ->cascadeOnDelete();

            $table->string('locale', 10)->index();
            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_index_translations');
    }
};
