<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('locale', 10)->index();
            $table->boolean('saved')->default(false);
            $table->boolean('published')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'saved'], 'lessons_user_saved_idx');
            $table->index(['user_id', 'published'], 'lessons_user_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
