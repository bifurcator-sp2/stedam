<?php

declare(strict_types=1);

namespace App\Infrastructure\Course\Persistence;

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\UserId;
use App\Models\Course as CourseModel;
use App\Models\CourseTheme as ThemeModel;
use App\Models\CourseThemeLesson as ThemeLessonModel;
use App\Models\LessonType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-репозиторий агрегата Course.
 *
 * Стратегия сохранения — «снеси и восстанови» для подчинённых коллекций
 * (темы и уроки): агрегат уже нормализован (order 1..N без дыр), поэтому
 * проще удалить отсутствующие строки и сделать upsert по оставшимся, чем
 * вычислять минимальный дифф. Тем и уроков в курсе немного (десятки).
 *
 * Всё в одной транзакции: частично сохранённого состава быть не должно.
 */
final readonly class EloquentCourseRepository implements CourseRepositoryInterface
{
    public function findById(CourseId $courseId): ?Course
    {
        $model = CourseModel::query()
            ->with([
                'themes' => fn ($q) => $q->orderBy('order'),
                'themes.lessons' => fn ($q) => $q->orderBy('order'),
            ])
            ->find($courseId->value);

        return $model === null ? null : $this->toDomain($model);
    }

    public function save(Course $course): Course
    {
        return DB::transaction(function () use ($course): Course {
            $model = $this->persistHeader($course);

            $this->persistThemes($model, $course);

            return $this->toDomain($model->fresh([
                'themes' => fn ($q) => $q->orderBy('order'),
                'themes.lessons' => fn ($q) => $q->orderBy('order'),
            ]) ?? $model);
        });
    }

    public function delete(CourseId $courseId): void
    {
        CourseModel::query()->whereKey($courseId->value)->delete();
    }

    public function listForOwner(
        ?UserId $ownerId,
        ?TaxonomyId $taxId,
        ?string $search,
        bool $includeSystem,
        bool $allOwners = false,
    ): array {
        $query = CourseModel::query()
            ->with([
                'themes' => fn ($q) => $q->orderBy('order'),
                'themes.lessons' => fn ($q) => $q->orderBy('order'),
            ]);

        if ($allOwners) {
            // Смотрящий видит всё (admin/methodologist/headteacher) — без фильтра.
        } elseif ($ownerId !== null) {
            if ($includeSystem) {
                $query->where(fn ($q) => $q
                    ->where('user_id', $ownerId->value)
                    ->orWhereNull('user_id'));
            } else {
                $query->where('user_id', $ownerId->value);
            }
        } elseif ($includeSystem) {
            // Без владельца показываем только системные курсы.
            // Раньше здесь не было фильтра вовсе — и гость видел ЧУЖИЕ курсы.
            $query->whereNull('user_id');
        } else {
            // Ни владельца, ни системных — показывать нечего.
            return [];
        }

        if ($taxId !== null) {
            $query->where('tax_id', $taxId->value);
        }

        if ($search !== null && $search !== '') {
            $query->where('title', 'like', '%' . $search . '%');
        }

        return $query
            ->orderBy('title')
            ->get()
            ->map(fn (CourseModel $model) => $this->toDomain($model))
            ->all();
    }

    public function listForStudent(UserId $studentId): array
    {
        $models = CourseModel::query()
            ->with([
                'themes' => fn ($q) => $q->orderBy('order'),
                'themes.lessons' => fn ($q) => $q->orderBy('order'),
            ])
            ->whereIn('id', function ($sub) use ($studentId) {
                $sub->select('course_id')
                    ->from('course_assignments')
                    ->where('student_id', $studentId->value);
            })
            ->orderBy('title')
            ->get();

        return $models
            ->map(fn (CourseModel $model) => $this->toDomain($model))
            ->all();
    }

    public function typeThresholds(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return LessonType::query()
            ->whereIn('id', $typeIds)
            ->pluck('next_lesson_threshold', 'id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    // === Маппинг БД → домен ===

    private function toDomain(CourseModel $model): Course
    {
        $themes = [];

        foreach ($model->themes as $themeModel) {
            $themes[] = $this->themeToDomain($themeModel);
        }

        return Course::restore(
            id: new CourseId($model->id),
            userId: $model->user_id === null ? null : new UserId((int) $model->user_id),
            title: new CourseTitle($model->title),
            description: new CourseDescription((string) ($model->description ?? '')),
            locale: new Locale($model->locale),
            nextLessonThreshold: new NextLessonThreshold((float) $model->next_lesson_threshold),
            taxId: $model->tax_id === null ? null : new TaxonomyId((int) $model->tax_id),
            themes: $themes,
            updatedAt: $model->updated_at === null
                ? null
                : DateTimeImmutable::createFromInterface($model->updated_at),
        );
    }

    private function themeToDomain(ThemeModel $model): CourseTheme
    {
        $lessons = [];

        foreach ($model->lessons as $lessonModel) {
            $lessons[] = new CourseLesson(
                lessonId: new LessonId((int) $lessonModel->lesson_id),
                order: (int) $lessonModel->order,
                typeId: $lessonModel->type_id === null
                    ? null
                    : new LessonTypeId((int) $lessonModel->type_id),
                timeGap: new TimeGap((int) $lessonModel->time_gap),
            );
        }

        return CourseTheme::restore(
            id: new CourseThemeId($model->id),
            title: $model->title,
            order: (int) $model->order,
            lessons: $lessons,
        );
    }

    // === Маппинг домен → БД ===

    private function persistHeader(Course $course): CourseModel
    {
        $id = $course->id();

        $model = $id === null
            ? new CourseModel()
            : CourseModel::query()->findOrFail($id->value);

        $model->user_id = $course->userId()?->value;
        $model->title = $course->title()->value;
        $model->description = $course->description()->value;
        $model->locale = $course->locale()->value;
        $model->next_lesson_threshold = $course->nextLessonThreshold()->value;
        $model->tax_id = $course->taxId()?->value;
        $model->save();

        return $model;
    }

    private function persistThemes(CourseModel $courseModel, Course $course): void
    {
        $keptThemeIds = [];
        $keptLessonIdsByTheme = [];

        foreach ($course->themes() as $theme) {
            $themeModel = $this->persistTheme($courseModel, $theme);
            $keptThemeIds[] = $themeModel->id;

            $keptLessonIdsByTheme[$themeModel->id] = $this->persistThemeLessons($themeModel, $theme);
        }

        // Удаляем темы, которых больше нет в агрегате.
        ThemeModel::query()
            ->where('course_id', $courseModel->id)
            ->when($keptThemeIds !== [], fn ($q) => $q->whereNotIn('id', $keptThemeIds))
            ->delete();

        // И уроки в оставшихся темах, которых больше нет.
        foreach ($keptLessonIdsByTheme as $themeId => $lessonIds) {
            ThemeLessonModel::query()
                ->where('theme_id', $themeId)
                ->when($lessonIds !== [], fn ($q) => $q->whereNotIn('id', $lessonIds))
                ->delete();
        }
    }

    private function persistTheme(CourseModel $courseModel, CourseTheme $theme): ThemeModel
    {
        $themeId = $theme->id();

        $model = $themeId === null
            ? new ThemeModel()
            : ThemeModel::query()->findOrFail($themeId->value);

        $model->course_id = $courseModel->id;
        $model->title = $theme->title();
        $model->order = $theme->order();
        $model->save();

        return $model;
    }

    /**
     * @return list<int> id сохранённых строк
     */
    private function persistThemeLessons(ThemeModel $themeModel, CourseTheme $theme): array
    {
        $kept = [];

        foreach ($theme->lessons() as $lesson) {
            $row = ThemeLessonModel::query()->firstOrNew([
                'theme_id' => $themeModel->id,
                'lesson_id' => $lesson->lessonId()->value,
            ]);

            $row->type_id = $lesson->typeId()?->value;
            $row->order = $lesson->order();
            $row->time_gap = $lesson->timeGap()->hours;
            $row->save();

            $kept[] = $row->id;
        }

        return $kept;
    }
}
