<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Проверяем, существует ли таблица и колонка, чтобы избежать ошибок на проде
        if (Schema::hasTable('file_sets') && Schema::hasColumn('file_sets', 'purpose')) {
            Schema::table('file_sets', function (Blueprint $table) {
                // Метод string() без указания длины по умолчанию создает VARCHAR(255)
                $table->string('purpose')->change();
            });
        }
    }

    public function down(): void
    {
        // В случае отката ничего не делаем, так как изначальный тип (возможно, varchar(255))
        // и так уже varchar(255), либо мы не хотим ломать данные на проде.
    }
};
