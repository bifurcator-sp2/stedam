<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Граф владения/наблюдения между пользователями.
 *
 * Одна generic-таблица вместо отдельных teacher_student / parent_student /
 * methodologist_teacher / headteacher_methodologist. Каждая связь — ребро
 * "хозяин → подчинённый" с типом:
 *
 *   teaches     teacher        → student
 *   parents     parent         → student   (наблюдение, read-only)
 *   supervises  methodologist  → teacher
 *   heads       headteacher    → methodologist
 *
 * active_from / active_to заложены на будущее (временные связи: смена учителя,
 * отпуск). Сейчас логика "действующей связи" трактует NULL active_to как
 * бессрочную связь; интервалы будет наполнять механизм распределения учителей
 * (утверждается позднее).
 *
 * Learn-контекст эти связи только читает. Наполнение — вне Learn (регистрация,
 * админка, будущий модуль распределения).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_relations', function (Blueprint $table) {
            $table->id();

            // "Хозяин" связи: teacher / methodologist / headteacher / parent.
            $table->foreignId('parent_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // "Подчинённый" связи: student / teacher / methodologist.
            $table->foreignId('child_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Тип ребра. Строкой (varchar) как в остальных enum'ах проекта.
            $table->string('relation', 32);

            // Период действия. NULL active_to = бессрочно.
            $table->timestampTz('active_from')->nullable();
            $table->timestampTz('active_to')->nullable();

            $table->timestampsTz();

            // Одна и та же пара в рамках одного типа ребра — единственна.
            $table->unique(
                ['parent_user_id', 'child_user_id', 'relation'],
                'user_relations_unique'
            );

            // Выборка "кого видит хозяин" и "кто видит подчинённого".
            $table->index(['parent_user_id', 'relation'], 'user_relations_parent_relation_idx');
            $table->index(['child_user_id', 'relation'], 'user_relations_child_relation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_relations');
    }
};
