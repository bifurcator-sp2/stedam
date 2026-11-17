<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Промежуток времени (time_gap) для типа урока.
 *
 * Целое число (например, в часах), по умолчанию — 12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_types', function (Blueprint $table) {
            $table->integer('time_gap')->default(12);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_types', function (Blueprint $table) {
            $table->dropColumn('time_gap');
        });
    }
};
