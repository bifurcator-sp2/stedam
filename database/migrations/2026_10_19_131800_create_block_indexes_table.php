<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_indexes', function (Blueprint $table) {
            $table->id();

            // -1 означает корневой узел (как в Taxonomy)
            $table->integer('parent_id')->default(-1)->index();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('order')->default(0)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_indexes');
    }
};
