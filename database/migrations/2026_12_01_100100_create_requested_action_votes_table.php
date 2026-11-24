<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Голоса кворума (2.4.1).
 *
 * Таблица НЕ партиционирована и НЕ имеет FK на `requested_actions`:
 * строка агрегата переезжает между партициями при смене complete, поэтому
 * FK на перемещаемую строку невозможен. Целостность держат:
 *  - Application: агрегат загружен FOR UPDATE до `record()`;
 *  - retention: `deleteForLeaf()` до DROP помесячного листа.
 *
 * Голос фиксирован: UNIQUE запрещает повторный голос одного приёмщика.
 * UPDATE голоса не существует.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requested_action_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requested_action_id');
            $table->boolean('requested_action_complete');
            $table->timestampTz('requested_action_created_at');
            $table->foreignId('responder_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 16);
            $table->timestampTz('decided_at')->useCurrent();

            $table->unique(
                ['requested_action_id', 'requested_action_complete', 'requested_action_created_at', 'responder_user_id'],
                'requested_action_votes_unique'
            );
            $table->index('requested_action_created_at', 'requested_action_votes_created_at_idx');
        });

        DB::statement(
            "ALTER TABLE requested_action_votes ADD CONSTRAINT requested_action_votes_decision_check
                CHECK (decision IN ('accepted','declined'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('requested_action_votes');
    }
};
