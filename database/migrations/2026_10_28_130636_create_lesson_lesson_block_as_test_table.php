<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_lesson_block_as_test', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();

            // ссылка на LessonBlock, у которого lesson_id = 0
            $table->foreignId('lesson_block_id')
                ->constrained('lesson_blocks')
                ->cascadeOnDelete();

            // позиция в объединённом списке блоков урока
            $table->unsignedInteger('order');

            $table->timestampsTz();

            // один и тот же "as test" блок может быть привязан к уроку только один раз
            $table->unique(['lesson_id', 'lesson_block_id']);

            // строгий порядок среди "as test" в пределах урока
            $table->unique(['lesson_id', 'order']);

            $table->index('lesson_block_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_lesson_block_as_test');
    }
};
