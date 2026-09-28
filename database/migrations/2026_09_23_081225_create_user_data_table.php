<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_data', function (Blueprint $table) {
            $table->id();

            // 1 пользователь = 1 запись
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();

            $table->unsignedSmallInteger('birth_year')->nullable();

            $table->enum('gender', ['male', 'female'])->nullable();

            $table->foreignId('country_id')
                ->nullable()
                ->constrained('countries')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_data');
    }
};
