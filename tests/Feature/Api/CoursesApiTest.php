<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function apiUserWithRole(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Связать родителя с ребёнком в графе user_relations (действующая связь).
 */
function linkParent(int $parentId, int $childId): void
{
    DB::table('user_relations')->insert([
        'parent_user_id' => $parentId,
        'child_user_id' => $childId,
        'relation' => 'parent',
        'active_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Валидное тело создания курса.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function coursePayload(array $overrides = []): array
{
    return [
        'title' => 'Основы Python',
        'description' => 'Курс для 8 класса',
        'locale' => 'ru',
        'threshold_multipler' => 1.0,
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------
// Доступ
// ---------------------------------------------------------------------

it('гость не имеет доступа к API курсов', function () {
    $this->getJson('/api/courses')->assertUnauthorized();
});

// ---------------------------------------------------------------------
// Создание
// ---------------------------------------------------------------------

it('учитель создаёт курс', function () {
    $teacher = apiUserWithRole('teacher');
    Sanctum::actingAs($teacher);

    $response = $this->postJson('/api/courses', coursePayload())->assertCreated();

    expect($response->json('title'))->toBe('Основы Python')
        ->and($response->json('user_id'))->toBe($teacher->id)
        ->and($response->json('is_system'))->toBeFalse()
        ->and($response->json('themes'))->toBe([])
        ->and($response->json('lessons_count'))->toBe(0);
});

it('курс без ключа user_id создаётся себе', function () {
    $teacher = apiUserWithRole('teacher');
    Sanctum::actingAs($teacher);

    $response = $this->postJson('/api/courses', coursePayload())->assertCreated();

    expect($response->json('user_id'))->toBe($teacher->id);
});

it('учитель не может создать системный курс', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $this->postJson('/api/courses', coursePayload(['user_id' => null]))
        ->assertForbidden()
        ->assertJsonPath('code', 'course_access_denied');
});

it('админ создаёт системный курс', function () {
    Sanctum::actingAs(apiUserWithRole('admin'));

    $response = $this->postJson('/api/courses', coursePayload(['user_id' => null]))
        ->assertCreated();

    expect($response->json('user_id'))->toBeNull()
        ->and($response->json('is_system'))->toBeTrue();
});

it('валидирует обязательные поля', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $this->postJson('/api/courses', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'description', 'locale', 'threshold_multipler']);
});

it('threshold_multipler обязателен при создании и должен быть положительным', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $this->postJson('/api/courses', coursePayload(['threshold_multipler' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['threshold_multipler']);

    $this->postJson('/api/courses', coursePayload(['threshold_multipler' => -0.5]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['threshold_multipler']);
});

it('threshold_multipler сохраняется, редактируется и отдаётся', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $response = $this->postJson('/api/courses', coursePayload(['threshold_multipler' => 1.5]))
        ->assertCreated();

    expect((float) $response->json('threshold_multipler'))->toBe(1.5);

    $id = $response->json('id');

    $this->patchJson("/api/courses/{$id}", ['threshold_multipler' => 0.75])
        ->assertOk()
        ->assertJsonPath('threshold_multipler', 0.75);

    expect(DB::table('courses')->where('id', $id)->value('threshold_multipler'))
        ->toEqual(0.75);

    $this->getJson("/api/courses/{$id}")
        ->assertOk()
        ->assertJsonPath('threshold_multipler', 0.75);
});

it('next_lesson_threshold не принимается и не отдаётся', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    // Порог курса не часть API: ключ игнорируется и не попадает в ответ.
    $response = $this->postJson('/api/courses', coursePayload(['next_lesson_threshold' => 0.6]))
        ->assertCreated()
        ->assertJsonMissingPath('next_lesson_threshold');

    $id = $response->json('id');

    $this->patchJson("/api/courses/{$id}", ['next_lesson_threshold' => 0.9])
        ->assertOk()
        ->assertJsonMissingPath('next_lesson_threshold');

    $this->getJson("/api/courses/{$id}")
        ->assertOk()
        ->assertJsonMissingPath('next_lesson_threshold');

    expect(DB::table('courses')->where('id', $id)->value('next_lesson_threshold'))
        ->toEqual(1.0);
});

// ---------------------------------------------------------------------
// Листинг и детальный просмотр
// ---------------------------------------------------------------------

it('учитель видит свой курс в списке', function () {
    $teacher = apiUserWithRole('teacher');
    Sanctum::actingAs($teacher);

    $this->postJson('/api/courses', coursePayload())->assertCreated();

    $list = $this->getJson('/api/courses')->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.title'))->toBe('Основы Python')
        ->and($list->json('meta.current_page'))->toBe(1)
        ->and($list->json('meta.per_page'))->toBe(20)
        ->and($list->json('meta.has_more_pages'))->toBeFalse()
        ->and($list->json('meta.from'))->toBe(1)
        ->and($list->json('meta.to'))->toBe(1)
        ->and($list->json('data.0.themes'))->toBeNull();
});

it('пагинация курсов листается по has_more_pages, как у уроков', function () {
    $teacher = apiUserWithRole('teacher');
    Sanctum::actingAs($teacher);

    foreach (['Первый', 'Второй', 'Третий'] as $title) {
        $this->postJson('/api/courses', coursePayload(['title' => $title]))->assertCreated();
    }

    $first = $this->getJson('/api/courses?per_page=2&page=1')->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta.current_page'))->toBe(1)
        ->and($first->json('meta.per_page'))->toBe(2)
        ->and($first->json('meta.has_more_pages'))->toBeTrue()
        ->and($first->json('meta.from'))->toBe(1)
        ->and($first->json('meta.to'))->toBe(2)
        ->and(array_keys($first->json('meta')))
        ->toBe(['current_page', 'per_page', 'has_more_pages', 'from', 'to']);

    $second = $this->getJson('/api/courses?per_page=2&page=2')->assertOk();

    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('meta.has_more_pages'))->toBeFalse()
        ->and($second->json('meta.from'))->toBe(3)
        ->and($second->json('meta.to'))->toBe(3);
});

it('пустой курс-листинг отдаёт from и to = null', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $list = $this->getJson('/api/courses')->assertOk();

    expect($list->json('data'))->toBe([])
        ->and($list->json('meta.has_more_pages'))->toBeFalse()
        ->and($list->json('meta.from'))->toBeNull()
        ->and($list->json('meta.to'))->toBeNull();
});

it('детальный курс отдаёт состав и флаги прав', function () {
    $teacher = apiUserWithRole('teacher');
    Sanctum::actingAs($teacher);

    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    $this->getJson("/api/courses/{$id}")
        ->assertOk()
        ->assertJsonPath('can.edit', true)
        ->assertJsonPath('can.delete', true)
        ->assertJsonPath('can.assign', true);
});

it('чужой авторский курс не виден учителю', function () {
    $author = apiUserWithRole('teacher');
    Sanctum::actingAs($author);
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    Sanctum::actingAs(apiUserWithRole('teacher'));

    $this->getJson("/api/courses/{$id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'course_access_denied');
});

it('несуществующий курс даёт 404', function () {
    Sanctum::actingAs(apiUserWithRole('admin'));

    $this->getJson('/api/courses/999999')
        ->assertNotFound()
        ->assertJsonPath('code', 'course_not_found');
});

// ---------------------------------------------------------------------
// Редактирование и удаление
// ---------------------------------------------------------------------

it('автор меняет шапку курса', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    $this->patchJson("/api/courses/{$id}", ['title' => 'Новое название'])
        ->assertOk()
        ->assertJsonPath('title', 'Новое название')
        // не переданные поля не меняются
        ->assertJsonPath('locale', 'ru');
});

it('чужой курс не редактируется', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    Sanctum::actingAs(apiUserWithRole('admin'));

    $this->patchJson("/api/courses/{$id}", ['title' => 'Hack'])
        ->assertForbidden()
        ->assertJsonPath('code', 'course_access_denied');
});

it('удаление возвращает 204 и курс пропадает', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    $this->deleteJson("/api/courses/{$id}")->assertNoContent();

    $this->getJson("/api/courses/{$id}")->assertNotFound();
});

// ---------------------------------------------------------------------
// Темы
// ---------------------------------------------------------------------

it('добавляет тему и возвращает полный курс', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    $response = $this->postJson("/api/courses/{$id}/themes", ['title' => 'Введение'])
        ->assertCreated();

    expect($response->json('themes'))->toHaveCount(1)
        ->and($response->json('themes.0.title'))->toBe('Введение')
        ->and($response->json('themes.0.order'))->toBe(1)
        ->and($response->json('themes.0.lessons'))->toBe([]);
});

it('переименовывает тему', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $themeId = $this->postJson("/api/courses/{$id}/themes", ['title' => 'Старая'])
        ->json('themes.0.id');

    $this->patchJson("/api/courses/{$id}/themes/{$themeId}", ['title' => 'Новая'])
        ->assertOk()
        ->assertJsonPath('themes.0.title', 'Новая');
});

it('удаляет тему', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $themeId = $this->postJson("/api/courses/{$id}/themes", ['title' => 'Тема'])
        ->json('themes.0.id');

    $this->deleteJson("/api/courses/{$id}/themes/{$themeId}")
        ->assertOk()
        ->assertJsonPath('themes', []);
});

it('валидирует название темы', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    $this->postJson("/api/courses/{$id}/themes", ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

// ---------------------------------------------------------------------
// Назначения
// ---------------------------------------------------------------------

it('назначает курс ученику и возвращает назначение', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $studentId = apiUserWithRole('student')->id;

    $response = $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
        ->assertCreated();

    expect($response->json('student_id'))->toBe($studentId)
        ->and($response->json('course_id'))->toBe($id)
        ->and((float) $response->json('completion'))->toBe(0.0)
        ->and((float) $response->json('grade'))->toBe(0.0);
});

it('повторное назначение даёт 409', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $studentId = apiUserWithRole('student')->id;

    $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
        ->assertCreated();

    $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
        ->assertStatus(409)
        ->assertJsonPath('code', 'course_already_assigned');
});

it('список назначений доступен автору', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $studentId = apiUserWithRole('student')->id;
    $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
        ->assertCreated();

    $list = $this->getJson("/api/courses/{$id}/assignments")->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.student_id'))->toBe($studentId);
});

it('снятие назначения возвращает 204', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $studentId = apiUserWithRole('student')->id;
    $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
        ->assertCreated();

    $this->deleteJson("/api/courses/{$id}/assignments/{$studentId}")->assertNoContent();

    expect($this->getJson("/api/courses/{$id}/assignments")->json('data'))->toHaveCount(0);
});

// ---------------------------------------------------------------------
// Видимость назначенных курсов (student / parent)
// ---------------------------------------------------------------------

it('студент видит только назначенный ему курс, в том числе системный', function () {
    // Авторский курс создаёт учитель; системный — только admin.
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $own = $this->postJson('/api/courses', coursePayload(['title' => 'Назначенный']))->json('id');
    $this->postJson('/api/courses', coursePayload(['title' => 'Не назначен']))->assertCreated();

    Sanctum::actingAs(apiUserWithRole('admin'));
    $systemId = $this->postJson('/api/courses', coursePayload(['title' => 'Системный', 'user_id' => null]))
        ->assertCreated()
        ->json('id');

    $student = apiUserWithRole('student');
    $this->postJson("/api/courses/{$own}/assignments", ['student_id' => $student->id])->assertCreated();
    $this->postJson("/api/courses/{$systemId}/assignments", ['student_id' => $student->id])->assertCreated();

    Sanctum::actingAs($student);

    $titles = array_column($this->getJson('/api/courses')->assertOk()->json('data'), 'title');

    expect($titles)->toEqualCanonicalizing(['Назначенный', 'Системный']);

    $this->getJson("/api/courses/{$own}")->assertOk()->assertJsonPath('can.see_progress', true);
});

it('студент не открывает системный курс, который ему не назначен', function () {
    Sanctum::actingAs(apiUserWithRole('admin'));
    $systemId = $this->postJson('/api/courses', coursePayload(['user_id' => null]))
        ->assertCreated()
        ->json('id');

    Sanctum::actingAs(apiUserWithRole('student'));

    // simple-пагинация: total нет, пустая страница — data: [] + has_more_pages: false.
    $this->getJson('/api/courses')->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.has_more_pages', false);

    $this->getJson("/api/courses/{$systemId}")
        ->assertForbidden()
        ->assertJsonPath('code', 'course_access_denied');
});

it('родитель видит и открывает курсы своих детей', function () {
    $teacher = apiUserWithRole('teacher');
    Sanctum::actingAs($teacher);
    $courseId = $this->postJson('/api/courses', coursePayload())->json('id');

    $student = apiUserWithRole('student');
    $this->postJson("/api/courses/{$courseId}/assignments", ['student_id' => $student->id])->assertCreated();

    $parent = apiUserWithRole('parent');
    linkParent($parent->id, $student->id);

    Sanctum::actingAs($parent);

    $list = $this->getJson('/api/courses')->assertOk();
    expect(array_column($list->json('data'), 'id'))->toBe([$courseId]);

    $this->getJson("/api/courses/{$courseId}")
        ->assertOk()
        ->assertJsonPath('can.edit', false)
        ->assertJsonPath('can.see_progress', true);
});

it('родитель без детей не видит курс', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $courseId = $this->postJson('/api/courses', coursePayload())->json('id');

    Sanctum::actingAs(apiUserWithRole('parent'));

    $this->getJson('/api/courses')->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.has_more_pages', false);
    $this->getJson("/api/courses/{$courseId}")->assertForbidden();
});

// ---------------------------------------------------------------------
// Валидация доменных VO
// ---------------------------------------------------------------------

it('некорректный locale отклоняется валидацией', function (?string $locale) {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $this->postJson('/api/courses', coursePayload(['locale' => $locale]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['locale']);
})->with(['ru-ru', 'russian', 'e', 'ru_RU']);

it('принимает региональный locale', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));

    $this->postJson('/api/courses', coursePayload(['locale' => 'en-US']))
        ->assertCreated()
        ->assertJsonPath('locale', 'en-US');
});

// ---------------------------------------------------------------------
// Пересчёт
// ---------------------------------------------------------------------

it('пересчёт по всем ученикам синхронный и возвращает 200 с числом', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    foreach ([1, 2] as $_) {
        $studentId = apiUserWithRole('student')->id;
        $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
            ->assertCreated();
    }

    $this->postJson("/api/courses/{$id}/recalculate")
        ->assertOk()
        ->assertJsonPath('processed', 2);
});

it('пересчёт по всем ученикам без назначений возвращает 0', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');

    $this->postJson("/api/courses/{$id}/recalculate")
        ->assertOk()
        ->assertJsonPath('processed', 0);
});

it('пересчёт по ученику возвращает назначение', function () {
    Sanctum::actingAs(apiUserWithRole('teacher'));
    $id = $this->postJson('/api/courses', coursePayload())->json('id');
    $studentId = apiUserWithRole('student')->id;
    $this->postJson("/api/courses/{$id}/assignments", ['student_id' => $studentId])
        ->assertCreated();

    $this->postJson("/api/courses/{$id}/assignments/{$studentId}/recalculate")
        ->assertOk()
        ->assertJsonPath('student_id', $studentId);
});
