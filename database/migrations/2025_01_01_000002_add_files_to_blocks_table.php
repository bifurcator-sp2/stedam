<?php
// database/migrations/2025_01_01_000002_add_files_to_blocks_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->json('images')->nullable()->after('id');
            $table->json('files')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn(['images', 'files']);
        });
    }
};
