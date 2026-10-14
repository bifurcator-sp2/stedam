<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });
        // 1. Добавляем колонку order
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->integer('order')->default(0)->after('parent_id');
        });

        // 2. Меняем default parent_id на -1 и делаем NOT NULL
        // Внимание: для существующих записей с NULL нужно сначала обновить данные
        DB::table('taxonomies')->whereNull('parent_id')->update(['parent_id' => -1]);

        Schema::table('taxonomies', function (Blueprint $table) {
            $table->integer('parent_id')->default(-1)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->dropColumn('order');
            $table->integer('parent_id')->nullable()->default(null)->change();
        });
    }
};
