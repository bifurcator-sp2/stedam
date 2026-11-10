<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фактический момент старта прохождения.
 *
 * Нужен, чтобы сравнить план (schedule_date) и факт (started_at):
 * отклонение считает домен (Learn::startedLateFromPlanInMinutes()).
 * NULL — прохождение ещё не начиналось (created/scheduled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->timestampTz('started_at')->nullable()->after('schedule_date');
            $table->index('started_at', 'learns_started_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->dropIndex('learns_started_at_idx');
            $table->dropColumn('started_at');
        });
    }
};
