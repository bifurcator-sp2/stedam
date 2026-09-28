<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_data', function (Blueprint $table) {
            $table->enum('direction', ['ltr', 'rtl'])
                ->default('ltr')
                ->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('user_data', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
