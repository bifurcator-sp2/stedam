<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
// sail artisan db:seed --class=DemoUsersSeeder
/**
 * Демо/тестовые пользователи для нагрузки и ручной проверки списков.
 *
 * 1000 пользователей с явными id 1000..1999:
 *   1000..1499 — student        (500)
 *   1500..1949 — parent         (450)
 *   1950..1989 — teacher        ( 40)
 *   1990..1997 — methodologist  (  8)
 *   1998..1999 — headteacher    (  2)
 *
 * email = u{id}@stedam.ru, name = u{id}, пароль = "123" (bcrypt),
 * email_verified_at — произвольная дата в прошлом.
 *
 * Связи (user_relations), полная цепочка. Значение relation = роль хозяина:
 *   parent        parent        → student   (у каждого ученика 1–3 родителя)
 *   teacher       teacher       → student   (у каждого ученика ровно 1 учитель)
 *   methodologist methodologist → teacher   (по 5 учителей на методиста)
 *   headteacher   headteacher   → methodologist (по 4 методиста на завуча)
 *
 * НЕ идемпотентно по данным: при каждом запуске СНАЧАЛА удаляет всё, что
 * создал предыдущий запуск (пользователи id 1000..1999, их роли и связи),
 * затем создаёт структуру заново «с чистого листа».
 */
class DemoUsersSeeder extends Seeder
{
    private const ID_START = 1000;

    private const STUDENTS  = 500;
    private const PARENTS   = 450;
    private const TEACHERS  = 40;
    private const METHODOLOGISTS = 8;
    private const HEADTEACHERS = 2;

    /** Общий диапазон id, которым владеет сидер. */
    private const ID_END_EXCLUSIVE = self::ID_START
        + self::STUDENTS + self::PARENTS + self::TEACHERS
        + self::METHODOLOGISTS + self::HEADTEACHERS;

    public function run(): void
    {
        // Роли должны существовать до выдачи.
        $this->call(RolesSeeder::class);

        // Чистим всё от предыдущего запуска — структура создаётся заново.
        $this->purgePreviousRun();

        // Диапазоны id по ролям (последовательно, без дыр).
        $studentFrom = self::ID_START;
        $parentFrom  = $studentFrom + self::STUDENTS;
        $teacherFrom = $parentFrom + self::PARENTS;
        $methodFrom  = $teacherFrom + self::TEACHERS;
        $headFrom    = $methodFrom + self::METHODOLOGISTS;

        $this->seedUsers($studentFrom, self::STUDENTS, 'student');
        $this->seedUsers($parentFrom, self::PARENTS, 'parent');
        $this->seedUsers($teacherFrom, self::TEACHERS, 'teacher');
        $this->seedUsers($methodFrom, self::METHODOLOGISTS, 'methodologist');
        $this->seedUsers($headFrom, self::HEADTEACHERS, 'headteacher');

        $studentIds = range($studentFrom, $studentFrom + self::STUDENTS - 1);
        $parentIds  = range($parentFrom, $parentFrom + self::PARENTS - 1);
        $teacherIds = range($teacherFrom, $teacherFrom + self::TEACHERS - 1);
        $methodIds  = range($methodFrom, $methodFrom + self::METHODOLOGISTS - 1);
        $headIds    = range($headFrom, $headFrom + self::HEADTEACHERS - 1);

        $this->linkHeadteachers($headIds, $methodIds);       // headteacher
        $this->linkMethodologists($methodIds, $teacherIds);  // methodologist
        $this->linkTeachers($teacherIds, $studentIds);       // teacher
        $this->linkParents($parentIds, $studentIds);         // parent

        $this->resetUsersSequence();
    }

    /**
     * Удаляет всё, что создал предыдущий запуск сидера:
     * связи (в обе стороны), назначения ролей Spatie и самих пользователей
     * в диапазоне id [ID_START, ID_END_EXCLUSIVE).
     *
     * Порядок важен из-за FK: сначала рёбра графа и model_has_roles,
     * затем users. user_relations чистим по parent_user_id и по
     * child_user_id отдельно — ребро может «держать» наш id с одной стороны.
     */
    private function purgePreviousRun(): void
    {
        $from = self::ID_START;
        $to   = self::ID_END_EXCLUSIVE; // exclusive

        // 1. Связи, где наш пользователь — хозяин или подчинённый.
        DB::table('user_relations')
            ->whereBetween('parent_user_id', [$from, $to - 1])
            ->orWhereBetween('child_user_id', [$from, $to - 1])
            ->delete();

        // 2. Назначения ролей (Spatie) и сами пользователи.
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereBetween('model_id', [$from, $to - 1])
            ->delete();

        DB::table('users')
            ->whereBetween('id', [$from, $to - 1])
            ->delete();
    }

    /**
     * Создаёт (или обновляет) диапазон пользователей и выдаёт им роль.
     */
    private function seedUsers(int $fromId, int $count, string $roleName): void
    {
        $role = Role::findByName($roleName, 'web');
        $now  = now()->format('Y-m-d H:i:s P');

        // Один и тот же хэш для всех: пароль "123". Считаем один раз.
        $passwordHash = Hash::make('123');

        for ($id = $fromId; $id < $fromId + $count; $id++) {
            DB::table('users')->updateOrInsert(
                ['id' => $id],
                [
                    'name'              => 'u' . $id,
                    'email'             => 'u' . $id . '@stedam.ru',
                    'email_verified_at' => $now,
                    'password'          => $passwordHash,
                    'remember_token'    => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]
            );

            /** @var User $user */
            $user = User::query()->findOrFail($id);
            // syncRoles идемпотентен: убирает лишние, добавляет недостающие.
            $user->syncRoles([$role]);
        }
    }

    /**
     * headteacher: headteacher → methodologist, методисты делятся ровно.
     *
     * @param int[] $headIds
     * @param int[] $methodIds
     */
    private function linkHeadteachers(array $headIds, array $methodIds): void
    {
        foreach ($methodIds as $i => $methodId) {
            $headId = $headIds[$i % count($headIds)];
            $this->insertRelation($headId, $methodId, 'headteacher');
        }
    }

    /**
     * methodologist: methodologist → teacher, по 5 учителей на методиста.
     *
     * @param int[] $methodIds
     * @param int[] $teacherIds
     */
    private function linkMethodologists(array $methodIds, array $teacherIds): void
    {
        foreach ($teacherIds as $i => $teacherId) {
            $methodId = $methodIds[$i % count($methodIds)];
            $this->insertRelation($methodId, $teacherId, 'methodologist');
        }
    }

    /**
     * teacher: teacher → student, у каждого ученика ровно 1 учитель.
     *
     * @param int[] $teacherIds
     * @param int[] $studentIds
     */
    private function linkTeachers(array $teacherIds, array $studentIds): void
    {
        foreach ($studentIds as $i => $studentId) {
            $teacherId = $teacherIds[$i % count($teacherIds)];
            $this->insertRelation($teacherId, $studentId, 'teacher');
        }
    }

    /**
     * parent: parent → student, у каждого ученика 1–3 родителя.
     *
     * Раскладка: i-й ученик получает (1 + i % 3) родителей; родители
     * выдаются по кругу с последовательными индексами, поэтому для одного
     * ученика пары уникальны.
     *
     * @param int[] $parentIds
     * @param int[] $studentIds
     */
    private function linkParents(array $parentIds, array $studentIds): void
    {
        $parentCount = count($parentIds);
        $cursor      = 0;

        foreach ($studentIds as $i => $studentId) {
            $parentsForStudent = 1 + ($i % 3); // 1,2,3,1,2,3,…
            for ($n = 0; $n < $parentsForStudent; $n++) {
                $parentId = $parentIds[$cursor % $parentCount];
                $cursor++;
                $this->insertRelation($parentId, $studentId, 'parent');
            }
        }
    }

    /**
     * Идемпотентная вставка ребра графа. unique-ключ
     * (parent_user_id, child_user_id, relation) отсекает дубли.
     */
    private function insertRelation(int $parentUserId, int $childUserId, string $relation): void
    {
        $now = now()->format('Y-m-d H:i:s P');

        DB::table('user_relations')->insertOrIgnore([
            'parent_user_id' => $parentUserId,
            'child_user_id'  => $childUserId,
            'relation'       => $relation,
            'active_from'    => null,
            'active_to'      => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }

    /**
     * После явной вставки id 1000..1999 сдвигаем PostgreSQL sequence,
     * чтобы обычный INSERT не падал на duplicate key.
     */
    private function resetUsersSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement(
                "SELECT setval(
                    pg_get_serial_sequence('users', 'id'),
                    (SELECT MAX(id) FROM users)
                )"
            );
        } catch (\Throwable $e) {
            $this->command?->warn(
                'DemoUsersSeeder: could not reset users sequence: ' . $e->getMessage()
            );
        }
    }
}
