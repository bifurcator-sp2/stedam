<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип урока — обязательное строковое поле.
 *
 * Хранится code из справочника lesson_types.code ('class_work', 'home_work', ...),
 * а не id: код стабилен и не зависит от пересида/перенумерации справочника.
 *
 * Дефолт 'class_work': урок создаётся классной работой без выбора типа в
 * запросе на создание. Менять тип можно, только пока урок не опубликован.
 *
 * FK на lesson_types не ставим: тип — доменное значение-код, а не ссылка
 * на строку справочника (справочник пересидируется независимо от уроков).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Новая колонка с NOT NULL — существующие строки получают DEFAULT.
            $table->string('type', 32)->default('class_work');
            $table->index('type', 'lessons_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('lessons_type_idx');
            $table->dropColumn('type');
        });
    }
};
