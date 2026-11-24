<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Попытки исполнения approved-агрегата (2.7, раздел 1 «Stale approved»).
 *
 * Без этой таблицы невозможно различить «handle() не запускался» и
 * «handle() запускался и падал»:
 *  - нет ни одной строки и approved старше T1  → HANDLE_STALE_NOT_STARTED;
 *  - есть строка и approved старше T2          → HANDLE_STALE_RETRY_EXHAUSTED.
 *
 * Строка пишется ДО вызова handle() отдельной транзакцией (HandleApprovedAction,
 * транзакция А) — поэтому «есть строка» ⇔ «handle() запускался».
 *
 * FK на `requested_actions` нет — та же причина, что у голосов (2.4.1).
 * Чистится retention'ом через `deleteForLeaf()` до DROP листа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requested_action_handle_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requested_action_id');
            $table->boolean('requested_action_complete');
            $table->timestampTz('requested_action_created_at');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->string('outcome', 64)->nullable();

            $table->index(
                ['requested_action_id', 'requested_action_complete', 'requested_action_created_at'],
                'requested_action_handle_attempts_ref_idx'
            );
            $table->index('requested_action_created_at', 'requested_action_handle_attempts_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requested_action_handle_attempts');
    }
};
