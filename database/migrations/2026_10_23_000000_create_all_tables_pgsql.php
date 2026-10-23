<?php

/**
 * Consolidated baseline migration for PostgreSQL.
 *
 * Represents the FINAL state of all previously applied migrations
 * (compressed from ~30 files into one). Use only on a FRESH PG database.
 *
 * PG-specific adaptations:
 *  - json        → jsonb
 *  - fullText    → expression GIN index on to_tsvector(...)
 *  - unsigned*   → plain integer types
 *  - enum        → varchar + CHECK (Laravel default on PG)
 *  - FK indexes  → created explicitly (PG doesn't auto-index FKs)
 *  - date/time   → timestamptz for DOMAIN tables (TZ-aware)
 *
 * TZ policy:
 *  - Domain tables use *Tz() variants (timestamptz).
 *  - System tables (Laravel core, Sanctum, Spatie) keep regular
 *    timestamps() since their vendor models don't set $dateFormat.
 *  - Domain models must set `protected $dateFormat = 'Y-m-d H:i:s P';`
 *    to avoid implicit timezone casting issues — see README note.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // INFRASTRUCTURE (Laravel defaults) — SYSTEM, keep regular timestamps
        // =====================================================================

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps(); // Sanctum — keep regular
        });

        // =====================================================================
        // PERMISSIONS (Spatie) — SYSTEM, keep regular timestamps
        // =====================================================================

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps(); // Spatie — keep regular
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps(); // Spatie — keep regular
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // =====================================================================
        // AUTH / USERS — DOMAIN, TZ-aware
        // =====================================================================

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestampsTz();
        });

        // App-owned extension of Spatie's role concept — DOMAIN, TZ-aware
        Schema::create('role_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unique(['role_id', 'locale']);
        });

        // =====================================================================
        // GEO / USER DATA — DOMAIN, TZ-aware
        // =====================================================================

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->char('iso3', 3)->nullable();
            $table->string('phone_code', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('country_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('name');
            $table->timestampsTz();
            $table->unique(['country_id', 'locale']);
        });

        Schema::create('user_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr');
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->timestampsTz();
            $table->index('country_id', 'user_data_country_id_idx');
        });

        // =====================================================================
        // FILE SETS — DOMAIN, TZ-aware
        // =====================================================================
        // Таблица может быть уже создана миграцией пакета
        // bifurcator/laravel-file-sets (2026_10_16_112200).

        if (!Schema::hasTable('file_sets')) {
            Schema::create('file_sets', function (Blueprint $table) {
                $table->id();
                $table->string('model');
                $table->unsignedBigInteger('model_id');
                $table->string('purpose')->default('cover');
                $table->jsonb('images')->nullable();
                $table->jsonb('files')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();
                $table->index(['model', 'model_id']);
                $table->unique(['model', 'model_id', 'purpose']);
            });
        }

        // =====================================================================
        // BLOCKS — DOMAIN, TZ-aware
        // =====================================================================

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('settings')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id', 'blocks_user_id_idx');
        });

        Schema::create('block_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unique(['block_id', 'locale']);
        });

        // =====================================================================
        // BLOCK INDEXES (tree) — DOMAIN, TZ-aware
        // =====================================================================

        Schema::create('block_indexes', function (Blueprint $table) {
            $table->id();
            $table->integer('parent_id')->default(-1)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('order')->default(0)->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id', 'block_indexes_user_id_idx');
        });

        Schema::create('block_index_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_index_id')->constrained('block_indexes')->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->index('block_index_id', 'block_index_translations_block_index_id_idx');
        });

        Schema::create('block_block_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_index_id')->constrained('block_indexes')->cascadeOnDelete();
            $table->foreignId('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->timestampsTz();
            $table->unique(['block_index_id', 'block_id']);
            $table->index(['block_index_id', 'order']);
            $table->index('block_id', 'block_block_index_block_id_idx');
        });

        // =====================================================================
        // LESSONS — DOMAIN, TZ-aware
        // =====================================================================

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('locale', 10)->index();
            $table->boolean('saved')->default(false);
            $table->boolean('published')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'saved'], 'lessons_user_saved_idx');
            $table->index(['user_id', 'published'], 'lessons_user_published_idx');
            $table->index(['user_id', 'id'], 'idx_lessons_user_id_id_desc');
        });

        // MySQL `fullText` replacement: expression GIN index over tsvector
        DB::statement(
            "CREATE INDEX idx_lessons_fulltext ON lessons USING GIN (
                to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, ''))
            )"
        );

        Schema::create('lesson_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('block_id')->nullable()->constrained('blocks')->nullOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons');
            $table->unsignedInteger('order')->default(0);
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['lesson_id', 'order'], 'lesson_blocks_lesson_order_idx');
            $table->index('user_id', 'lesson_blocks_user_idx');
            $table->index('block_id', 'lesson_blocks_block_id_idx');
        });

        // =====================================================================
        // TAXONOMIES — DOMAIN, TZ-aware
        // =====================================================================

        Schema::create('taxonomies', function (Blueprint $table) {
            $table->id();
            $table->integer('parent_id')->default(-1);
            $table->integer('order')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('parent_id');
            $table->index('user_id');
        });

        Schema::create('taxonomy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unique(['taxonomy_id', 'locale']);
            $table->index('locale');
        });

        Schema::create('lesson_taxonomy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique('lesson_id');
            $table->index(['taxonomy_id', 'lesson_id'], 'idx_lesson_taxonomy_tax_lesson');
            $table->index(['lesson_id', 'taxonomy_id'], 'idx_lesson_taxonomy_lesson_tax');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_taxonomy');
        Schema::dropIfExists('taxonomy_translations');
        Schema::dropIfExists('taxonomies');
        Schema::dropIfExists('lesson_blocks');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('block_block_index');
        Schema::dropIfExists('block_index_translations');
        Schema::dropIfExists('block_indexes');
        Schema::dropIfExists('block_translations');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('file_sets');
        Schema::dropIfExists('user_data');
        Schema::dropIfExists('country_translations');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('role_translations');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
