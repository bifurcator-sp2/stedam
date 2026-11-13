<?php

declare(strict_types=1);

namespace App\Infrastructure\Course\Context;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Context\CourseViewerRole;
use App\Application\Learn\Context\RelationType;
use App\Domain\Course\ValueObjects\UserId;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;

/**
 * Собирает CourseViewerContext из текущего Laravel-пользователя.
 *
 * Роль выбирается по приоритету: высшая роль выигрывает. Причина: один
 * пользователь может иметь несколько ролей (админ, который также ведёт
 * курс как учитель), и матрица прав должна учитывать сильнейшую.
 *
 * Гость (нет пользователя) — роль guest: видит только системные курсы.
 *
 * Почему отдельный класс, а не статический хелпер в контроллере:
 * контекст нужен всем методам CoursesController; дублировать сборку
 * ролей в 15 местах — гарантированные расхождения.
 */
final readonly class EloquentCourseContextResolver
{
    /**
     * Порядок важен: первая совпавшая роль выигрывает.
     *
     * @var list<array{0: CourseViewerRole, 1: list<string>}>
     */
    private const ROLE_MAP = [
        [CourseViewerRole::Admin, ['admin', 'super_admin', 'administrator']],
        [CourseViewerRole::Methodologist, ['methodologist', 'methodist']],
        [CourseViewerRole::Headteacher, ['headteacher', 'head_teacher', 'zavuch']],
        [CourseViewerRole::Teacher, ['teacher']],
        [CourseViewerRole::Parent, ['parent']],
        [CourseViewerRole::Student, ['student', 'pupil']],
    ];

    public function __construct(
        private AuthFactory $auth,
    ) {}

    public function resolve(): CourseViewerContext
    {
        /** @var User|null $user */
        $user = $this->auth->guard()->user();

        if ($user === null) {
            return CourseViewerContext::guest();
        }

        $userId = new UserId((int) $user->id);
        $role = $this->roleOf($user);

        return match ($role) {
            CourseViewerRole::Admin => CourseViewerContext::admin($userId),
            CourseViewerRole::Methodologist => CourseViewerContext::methodologist($userId),
            CourseViewerRole::Headteacher => CourseViewerContext::headteacher($userId),
            CourseViewerRole::Teacher => CourseViewerContext::teacher($userId),
            // Родитель видит курсы, назначенные его детям, поэтому дети
            // загружаются сразу: контекст остаётся чистой проверкой по данным,
            // без походов в БД из Application-слоя.
            CourseViewerRole::Parent => CourseViewerContext::parent($userId, $this->childStudentIds($userId->value)),
            CourseViewerRole::Student => CourseViewerContext::student($userId),
            CourseViewerRole::Guest => CourseViewerContext::guest(),
        };
    }

    /**
     * Дети родителя: действующие связи user_relations (relation=parent).
     *
     * «Действующая» — active_to IS NULL или в будущем; так же трактует граф
     * EloquentContextResolver в контексте Learn — чтобы scope ролей совпадал.
     *
     * @return list<UserId>
     */
    private function childStudentIds(int $parentId): array
    {
        return DB::table('user_relations')
            ->where('parent_user_id', $parentId)
            ->where('relation', RelationType::Parent->value)
            ->where(function ($q): void {
                $q->whereNull('active_to')
                    ->orWhere('active_to', '>', now());
            })
            ->orderBy('child_user_id')
            ->pluck('child_user_id')
            ->map(static fn ($id): UserId => new UserId((int) $id))
            ->all();
    }

    private function roleOf(User $user): CourseViewerRole
    {
        // Spatie-роли: $user->getRoleNames() возвращает коллекцию строк.
        // Проверяем осторожно: метод есть только при подключённом трейте.
        $names = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->map(static fn ($name): string => (string) $name)->all()
            : [];

        foreach (self::ROLE_MAP as [$role, $aliases]) {
            foreach ($aliases as $alias) {
                if (in_array($alias, $names, true)) {
                    return $role;
                }
            }
        }

        return CourseViewerRole::Student;
    }
}
