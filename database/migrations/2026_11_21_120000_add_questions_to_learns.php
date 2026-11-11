<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Материализованные задачи прохождения (уникальные для каждого learn).
 *
 * Список QuestionItem вида:
 *   [{ index, question, answer, solution, topic, difficulty, seed }, ...]
 *
 * index — позиция генеративного блока в allBlocksOrdered() + 1,
 * та же семантика, что и у answers[].index (AnswerImage::index).
 *
 * В questions попадают ТОЛЬКО генеративные блоки: статические блоки
 * и содержимое урока живут в снапшоте. NULL — для записей до генерации
 * (created/scheduled) и для легаси-строк.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->jsonb('questions')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->dropColumn('questions');
        });
    }
};
