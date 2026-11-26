<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\LessonTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function lessonFor(User $user, array $overrides = []): Lesson
{
    return Lesson::query()->create([
        'user_id' => $user->id,
        'locale' => 'ru',
        'type' => 'home_work',
        'saved' => true,
        'published' => false,
        'title' => 'Урок',
        'description' => 'Описание',
        ...$overrides,
    ]);
}

it('GET /api/lessons/{id} возвращает справочник типов урока', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user);

    $response = $this->getJson(
        "/api/lessons/{$lesson->id}",
        ['Accept-Language' => 'ru'],
    )->assertOk();

    // Справочник один, как и у списка: все типы в порядке sort.
    expect($response->json('type'))->toBe('home_work')
        ->and($response->json('lesson_types'))->toHaveCount(4)
        ->and(collect($response->json('lesson_types'))->pluck('code')->all())
        ->toBe(['class_work', 'home_work', 'ind_work', 'control_work'])
        ->and($response->json('lesson_types.0'))
        ->toMatchArray([
            'id' => \App\Models\LessonType::whereCode('class_work')->value('id'),
            'code' => 'class_work',
            'title' => 'Классная работа',
            'description' => 'Работа выполняется на уроке',
            'color' => '#4CAF50',
            'sort' => 10,
            'next_lesson_threshold' => 0.75,
            'time_gap' => 12,
        ]);
});

it('GET /api/lessons/types отдаёт справочник с локализованными названиями', function () {
    $this->seed(LessonTypeSeeder::class);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/lessons/types', ['Accept-Language' => 'ru'])
        ->assertOk()
        ->assertJsonCount(4, 'data');

    expect(collect($response->json('data'))->pluck('code')->all())
        ->toBe(['class_work', 'home_work', 'ind_work', 'control_work'])
        ->and($response->json('data.1'))
        ->toMatchArray([
            'id' => \App\Models\LessonType::whereCode('home_work')->value('id'),
            'code' => 'home_work',
            'title' => 'Домашняя работа',
            'description' => 'Работа выполняется дома',
            'color' => '#2196F3',
            'sort' => 20,
            'next_lesson_threshold' => 0.333,
            'time_gap' => 4,
        ]);
});

it('GET /api/lessons/types локализует по Accept-Language', function () {
    $this->seed(LessonTypeSeeder::class);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/lessons/types', ['Accept-Language' => 'en'])
        ->assertOk();

    expect($response->json('data.0.title'))->toBe('Class Work')
        ->and($response->json('data.0.description'))->toBe('Work performed during the lesson');
});

it('GET /api/lessons/types отдаёт fallback, если текущей локали нет', function () {
    $this->seed(LessonTypeSeeder::class);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/lessons/types', ['Accept-Language' => 'de'])
        ->assertOk();

    // Локали `de` нет ни у одного типа — действует app.fallback_locale (en).
    expect($response->json('data.0.title'))->toBe('Class Work');
});

it('GET /api/lessons/types требует авторизации', function () {
    $this->getJson('/api/lessons/types')->assertUnauthorized();
});

it('GET /api/lessons/{id} локализует названия типов по Accept-Language', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user);

    $response = $this->getJson("/api/lessons/{$lesson->id}", ['Accept-Language' => 'en'])
        ->assertOk();

    expect($response->json('lesson_types.0.title'))->toBe('Class Work');
});

it('GET /api/lessons отдаёт справочник типов урока для списка', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    lessonFor($user, ['title' => 'Первый']);
    lessonFor($user, ['title' => 'Второй', 'type' => 'ind_work']);

    $response = $this->getJson('/api/lessons', ['Accept-Language' => 'ru'])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Справочник — как в БД: по sort, без перестановки «тип первого урока».
    expect(collect($response->json('lesson_types'))->pluck('code')->all())
        ->toBe(['class_work', 'home_work', 'ind_work', 'control_work'])
        ->and($response->json('lesson_types.0.code'))->toBe('class_work')
        ->and($response->json('lesson_types.0.title'))->toBe('Классная работа')
        ->and(collect($response->json('data'))->pluck('type')->all())
        // Список отсортирован по id DESC (свежие первыми).
        ->toBe(['ind_work', 'home_work']);

    // Справочник — один на ответ, а не в каждом элементе списка.
    $response->assertJsonPath('data.0.lesson_types', null);
});

it('в ответах make и save справочник типов не отдаётся', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/lessons/make', ['type' => 'home_work'])
        ->assertCreated()
        ->assertJsonMissingPath('lesson_types');

    $lesson = lessonFor($user);

    $this->putJson("/api/lessons/{$lesson->id}", ['type' => 'ind_work'])
        ->assertOk()
        ->assertJsonPath('type', 'ind_work')
        ->assertJsonMissingPath('lesson_types');
});

it('смена типа опубликованного урока запрещена, даже если тот же запрос снимает публикацию', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user, ['published' => true]);

    // Снятие публикации атомарно: тип из того же запроса не применяется.
    $this->putJson("/api/lessons/{$lesson->id}", [
        'type' => 'ind_work',
        'published' => false,
    ])->assertOk()->assertJsonPath('type', 'home_work');

    $fresh = $lesson->fresh();
    expect($fresh->type)->toBe('home_work')->and($fresh->published)->toBeFalse();
});

it('смена типа неопубликованного урока с одновременной публикацией разрешена', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user, ['published' => false]);

    $this->putJson("/api/lessons/{$lesson->id}", [
        'type' => 'control_work',
        'published' => true,
    ])->assertOk()->assertJsonPath('type', 'control_work');
});

it('опубликованный урок нельзя изменить, не сняв публикацию', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user, ['published' => true, 'title' => 'Старое']);

    $this->putJson("/api/lessons/{$lesson->id}", [
        'title' => 'Новое',
        'description' => 'Правка',
    ])->assertStatus(422);

    $this->putJson("/api/lessons/{$lesson->id}", ['saved' => true])
        ->assertStatus(422);

    $fresh = $lesson->fresh();
    expect($fresh->title)->toBe('Старое')
        ->and($fresh->description)->toBe('Описание')
        ->and($fresh->published)->toBeTrue();
});

it('опубликованный урок можно снять с публикации', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user, ['published' => true, 'saved' => true]);

    $this->putJson("/api/lessons/{$lesson->id}", ['published' => false])
        ->assertOk()
        ->assertJsonPath('published', false);

    expect($lesson->fresh()->published)->toBeFalse();
});

it('после снятия публикации урок снова редактируется', function () {
    $this->seed(LessonTypeSeeder::class);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user, ['published' => true]);

    $this->putJson("/api/lessons/{$lesson->id}", ['published' => false])
        ->assertOk();

    $this->putJson("/api/lessons/{$lesson->id}", [
        'title' => 'Обновлённый',
        'type' => 'ind_work',
    ])->assertOk()
        ->assertJsonPath('title', 'Обновлённый')
        ->assertJsonPath('type', 'ind_work')
        ->assertJsonPath('published', false);

    $fresh = $lesson->fresh();
    expect($fresh->title)->toBe('Обновлённый')
        ->and($fresh->type)->toBe('ind_work')
        ->and($fresh->published)->toBeFalse();
});

it('снятие публикации атомарно: правки в том же запросе игнорируются', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lesson = lessonFor($user, ['published' => true, 'title' => 'Старое']);

    $this->putJson("/api/lessons/{$lesson->id}", [
        'published' => false,
        'title' => 'Новое',
        'description' => 'Другое',
    ])->assertOk()->assertJsonPath('published', false);

    $fresh = $lesson->fresh();
    expect($fresh->published)->toBeFalse()
        ->and($fresh->title)->toBe('Старое')
        ->and($fresh->description)->toBe('Описание');

    // И только теперь содержимое редактируется.
    $this->putJson("/api/lessons/{$lesson->id}", ['title' => 'Новое'])
        ->assertOk()
        ->assertJsonPath('title', 'Новое');

    expect($lesson->fresh()->title)->toBe('Новое');
});
