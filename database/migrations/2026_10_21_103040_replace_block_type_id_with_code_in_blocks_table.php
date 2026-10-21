<?php
// database/migrations/xxxx_xx_xx_xxxxxx_replace_block_type_id_with_code_in_blocks_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            // Добавляем поле code
            $table->string('code')->nullable()->after('id');
        });

        // Переносим старые данные (если есть)
        DB::statement("
            UPDATE blocks
            SET code = (SELECT code FROM block_types WHERE block_types.id = blocks.block_type_id)
            WHERE block_type_id IS NOT NULL
        ");

        Schema::table('blocks', function (Blueprint $table) {
            $table->string('code')->nullable(false)->change();
            $table->dropForeign(['block_type_id']);
            $table->dropColumn('block_type_id');
        });

        // Удаляем таблицы типов блоков
        Schema::dropIfExists('block_type_translations');
        Schema::dropIfExists('block_types');
    }

    public function down(): void
    {
        Schema::create('block_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');
            $table->json('default_settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('blocks', function (Blueprint $table) {
            $table->foreignId('block_type_id')->nullable()->constrained('block_types');
            $table->dropColumn('code');
        });
    }
};
