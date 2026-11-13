<?php

declare(strict_types=1);

use App\Application\Course\Ports\LessonReaderInterface;
use App\Domain\Course\ValueObjects\LessonId;
use App\Infrastructure\Course\Adapters\LessonReaderAdapter;
use App\Models\Lesson;
use App\Models\LessonTaxonomy;
use App\Models\Taxonomy;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Фабрик в проекте нет — создаём записи через модели явно.
 */
function makeUser(): User
{
    return User::query()->create([
        'name' => 'Тест',
        'email' => 'test' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
}

/**
 * Урок с заданной сложностью.
 *
 * complexity НЕ в $fillable и пересчитывается хуком saving из блоков-заданий,
 * поэтому задаём его запросом мимо массового заполнения — иначе хук обнулит.
 */
function makeLesson(?float $complexity = null): Lesson
{
    $lesson = Lesson::query()->create([
        'user_id' => makeUser()->id,
        'title' => 'Урок',
        'locale' => 'ru',
    ]);

    if ($complexity !== null) {
        Lesson::query()->whereKey($lesson->id)->update(['complexity' => $complexity]);
        $lesson->refresh();
    }

    return $lesson;
}

function makeTaxonomy(int $parentId = -1): Taxonomy
{
    return Taxonomy::query()->create(['parent_id' => $parentId]);
}

beforeEach(function () {
    $this->reader = app(LessonReaderInterface::class);
});

it('резолвится из контейнера', function () {
    expect($this->reader)->toBeInstanceOf(LessonReaderAdapter::class);
});

it('возвращает сложность урока', function () {
    $lesson = makeLesson(0.75);

    expect($this->reader->complexityOf(new LessonId($lesson->id)))->toBe(0.75);
});

it('возвращает null для несуществующего урока', function () {
    expect($this->reader->complexityOf(new LessonId(99999)))->toBeNull();
});

it('возвращает null, если сложность не задана', function () {
    $lesson = makeLesson(null);

    expect($this->reader->complexityOf(new LessonId($lesson->id)))->toBeNull();
});

it('возвращает сложности пачкой, включая null для отсутствующих', function () {
    $a = makeLesson(0.25);
    $b = makeLesson(0.5);

    $result = $this->reader->complexitiesOf([
        new LessonId($a->id),
        new LessonId($b->id),
        new LessonId(99999),
    ]);

    expect($result)->toBe([
        $a->id => 0.25,
        $b->id => 0.5,
        99999 => null,
    ]);
});

it('возвращает пустой массив на пустой вход', function () {
    expect($this->reader->complexitiesOf([]))->toBe([])
        ->and($this->reader->taxonomyIdsOf([]))->toBe([]);
});

it('определяет существование урока', function () {
    $lesson = makeLesson();

    expect($this->reader->exists(new LessonId($lesson->id)))->toBeTrue()
        ->and($this->reader->exists(new LessonId(99999)))->toBeFalse();
});

it('возвращает ветку таксономии урока', function () {
    $lesson = makeLesson();
    $taxonomy = makeTaxonomy();

    LessonTaxonomy::query()->create([
        'lesson_id' => $lesson->id,
        'taxonomy_id' => $taxonomy->id,
    ]);

    expect($this->reader->taxonomyIdOf(new LessonId($lesson->id)))->toBe($taxonomy->id);
});

it('возвращает null, если урок без ветки', function () {
    $lesson = makeLesson();

    expect($this->reader->taxonomyIdOf(new LessonId($lesson->id)))->toBeNull();
});

it('возвращает ветки пачкой одним запросом', function () {
    $taxonomy = makeTaxonomy();
    $withTax = makeLesson();
    $withoutTax = makeLesson();

    LessonTaxonomy::query()->create([
        'lesson_id' => $withTax->id,
        'taxonomy_id' => $taxonomy->id,
    ]);

    $result = $this->reader->taxonomyIdsOf([
        new LessonId($withTax->id),
        new LessonId($withoutTax->id),
        new LessonId(99999),
    ]);

    expect($result)->toBe([
        $withTax->id => $taxonomy->id,
        $withoutTax->id => null,
        99999 => null,
    ]);
});

it('собирает поддерево таксономии, включая сам узел', function () {
    $root = makeTaxonomy();
    $child = makeTaxonomy($root->id);
    $grandchild = makeTaxonomy($child->id);
    $sibling = makeTaxonomy($root->id);

    $subtree = $this->reader->taxonomySubtreeIds($child->id);

    sort($subtree);

    expect($subtree)->toBe([$child->id, $grandchild->id])
        ->and($subtree)->not->toContain($sibling->id)
        ->and($subtree)->not->toContain($root->id);
});

it('для листа поддерево — он сам', function () {
    $leaf = makeTaxonomy();

    expect($this->reader->taxonomySubtreeIds($leaf->id))->toBe([$leaf->id]);
});
