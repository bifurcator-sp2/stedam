<?php

// database/migrations/2026_10_16_XXXXXX_drop_images_and_files_from_blocks_and_lesson_blocks.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ============================================================
        // blocks
        // ============================================================

        Schema::table('blocks', function (Blueprint $table) {
            if (Schema::hasColumn('blocks', 'images')) {
                $table->dropColumn('images');
            }

            if (Schema::hasColumn('blocks', 'files')) {
                $table->dropColumn('files');
            }
        });

        // ============================================================
        // lesson_blocks
        // ============================================================

        Schema::table('lesson_blocks', function (Blueprint $table) {
            if (Schema::hasColumn('lesson_blocks', 'images')) {
                $table->dropColumn('images');
            }

            if (Schema::hasColumn('lesson_blocks', 'files')) {
                $table->dropColumn('files');
            }
        });
    }

    public function down(): void
    {
        // ============================================================
        // blocks
        // ============================================================

        Schema::table('blocks', function (Blueprint $table) {
            if (!Schema::hasColumn('blocks', 'images')) {
                $table->json('images')->nullable()->after('settings');
            }

            if (!Schema::hasColumn('blocks', 'files')) {
                $table->json('files')->nullable()->after('images');
            }
        });

        // ============================================================
        // lesson_blocks
        // ============================================================

        Schema::table('lesson_blocks', function (Blueprint $table) {
            if (!Schema::hasColumn('lesson_blocks', 'images')) {
                $table->json('images')->nullable()->after('settings');
            }

            if (!Schema::hasColumn('lesson_blocks', 'files')) {
                $table->json('files')->nullable()->after('images');
            }
        });
    }
};
