<?php
// database/migrations/xxxx_xx_xx_xxxxxx_make_lesson_id_nullable_on_lesson_blocks.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lesson_blocks', function (Blueprint $table) {
            // Меняем на nullable, внешний ключ (FK) при этом сохраняется
            $table->unsignedBigInteger('lesson_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('lesson_id')->nullable(false)->change();
        });
    }
};
