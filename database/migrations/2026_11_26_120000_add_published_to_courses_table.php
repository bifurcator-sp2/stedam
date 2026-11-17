<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Признак опубликованности курса — работает так же, как lessons.published.
 *
 * Дефолт false: новый курс черновик. Опубликованный курс нельзя редактировать —
 * единственное допустимое изменение published = false (атомарное снятие
 * публикации); прочие правки запроса игнорируются.
 *
 * Курсы, созданные до миграции, остаются черновиками (DEFAULT FALSE), что
 * сохраняет прежнее поведение: публикации в домене не было вовсе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('published')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
