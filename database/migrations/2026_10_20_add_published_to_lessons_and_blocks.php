<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Добавляем published в lessons, если её ещё нет
        Schema::table('lessons', function (Blueprint $table) {
            if (!Schema::hasColumn('lessons', 'published')) {
                $table->boolean('published')->default(false)->after('description')->index();
            }
        });

        // Добавляем published в blocks
        Schema::table('blocks', function (Blueprint $table) {
            if (!Schema::hasColumn('blocks', 'published')) {
                $table->boolean('published')->default(false)->after('settings')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            if (Schema::hasColumn('lessons', 'published')) {
                $table->dropColumn('published');
            }
        });

        Schema::table('blocks', function (Blueprint $table) {
            if (Schema::hasColumn('blocks', 'published')) {
                $table->dropColumn('published');
            }
        });
    }
};
