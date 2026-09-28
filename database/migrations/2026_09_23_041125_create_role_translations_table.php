<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['role_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_translations');
    }
};
