<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // double precision = float8, ближе всего к PHP float
            $table->double('complexity')->nullable();
            $table->index('complexity');
        });

        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->double('complexity')->nullable();
            $table->index('complexity');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['complexity']);
            $table->dropColumn('complexity');
        });

        Schema::table('lesson_blocks', function (Blueprint $table) {
            $table->dropIndex(['complexity']);
            $table->dropColumn('complexity');
        });
    }
};
