<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оценка появляется только при переходе в graded.
 * До этого score отсутствует ⇒ колонка должна быть nullable,
 * иначе insert прохождения без оценки падает на NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->float('score')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('learns', function (Blueprint $table) {
            $table->float('score')->nullable(false)->default(0)->change();
        });
    }
};
