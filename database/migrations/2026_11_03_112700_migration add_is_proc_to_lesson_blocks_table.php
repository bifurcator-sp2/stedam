<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_blocks', function (Blueprint $table) {
            // По умолчанию false (обычное задание), с индексом для быстрой фильтрации
            $table->boolean('is_proc')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_blocks', function (Blueprint $table) {
            // Откатываем индекс и колонку
            $table->dropIndex(['is_proc']);
            $table->dropColumn('is_proc');
        });
    }
};
