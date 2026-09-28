<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();      // RU, US, DE
            $table->char('iso3', 3)->nullable();    // RUS, USA, DEU
            $table->string('phone_code', 10)->nullable(); // +7, +1
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
