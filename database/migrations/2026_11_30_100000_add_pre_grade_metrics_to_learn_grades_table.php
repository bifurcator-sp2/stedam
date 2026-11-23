<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дополнительные данные предварительной (автоматической) оценки.
 *
 * pre_grade_coverage — полнота/покрытие ответа (доля 0..1).
 * pre_grade_info   — служебная информация проверки (JSON, например комментарий и токены).
 * pre_grade_cost   — стоимость проверки.
 * pre_grade_time   — затраченное время, сек.
 * pre_grade_memory — затраченная память, МБ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->float('pre_grade_coverage')->unsigned()->nullable();
            $table->json('pre_grade_info')->nullable();
            $table->float('pre_grade_cost')->unsigned()->nullable();
            $table->float('pre_grade_time')->unsigned()->nullable();
            $table->float('pre_grade_memory')->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learn_grades', function (Blueprint $table) {
            $table->dropColumn([
                'pre_grade_coverage',
                'pre_grade_info',
                'pre_grade_cost',
                'pre_grade_time',
                'pre_grade_memory',
            ]);
        });
    }
};
