<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('taxonomies')
                ->nullOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('user_id');
        });

        Schema::create('taxonomy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_id')
                ->constrained('taxonomies')
                ->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['taxonomy_id', 'locale']);
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_translations');
        Schema::dropIfExists('taxonomies');
    }
};
