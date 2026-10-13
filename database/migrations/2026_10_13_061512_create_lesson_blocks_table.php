<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_blocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users');

            $table->foreignId('block_id')
                ->nullable()
                ->constrained('blocks')
                ->nullOnDelete();

            $table->foreignId('lesson_id')
                ->constrained('lessons');

            $table->unsignedInteger('order')->default(0);

            $table->json('settings')->nullable();
            $table->json('files')->nullable();
            $table->json('images')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Составной индекс: выборка блоков урока в порядке
            $table->index(['lesson_id', 'order'], 'lesson_blocks_lesson_order_idx');

            // Индекс для выборки по пользователю
            $table->index('user_id', 'lesson_blocks_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_blocks');
    }
};
