<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * Восстанавливает admin (id=1) из старого дампа и создаёт owline (id=3).
     * Всем выдаются все существующие роли.
     *
     * Идемпотентно: можно запускать повторно, данные не задвоятся.
     */
    public function run(): void
    {
        // Роли должны существовать до выдачи
        $this->call(RolesSeeder::class);

        $this->upsertAdmin();
        $this->upsertOwline();

        $this->assignAllRolesTo([1, 3]);
        $this->resetUsersSequence();
    }

    // ============================================================
    // Пользователи
    // ============================================================

    protected function upsertAdmin(): void
    {
        // Пароль уже захеширован (bcrypt, cost=12) — НЕ перехешируем.
        // При логине Hash::check($plain, $this->password) сверит ввод
        // с этим хешем и вернёт true, если пароль верный.
        DB::table('users')->updateOrInsert(
            ['id' => 1],
            [
                'name'              => 'admin',
                'email'             => 'bifurcator@yandex.ru',
                'email_verified_at' => null,
                'password'          => '$2y$12$qbIsNgftiHEJpP7j4mFyL.vO41NyY/xgSqekBgO9hfAYGVOpER6Ry',
                'remember_token'    => 'TzW9FawyAB3CQu2DmOCpZfdExGRrCjqNXuVDcbNOOw5fLqfOvwYCZBHVxLJ8',
                // +00 = явный UTC. Без него PG подставит TZ сессии — «плавающее» время.
                'created_at'        => '2026-09-19 03:58:12+00',
                'updated_at'        => '2026-09-24 07:14:36+00',
            ]
        );
    }

    protected function upsertOwline(): void
    {
        $now = now()->format('Y-m-d H:i:s P');

        DB::table('users')->updateOrInsert(
            ['id' => 3],
            [
                'name'              => 'owline',
                'email'             => 'owline@yandex.ru',
                'email_verified_at' => $now,
                'password'          => Hash::make('esW14Txx'),
                'remember_token'    => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]
        );
    }

    // ============================================================
    // Роли
    // ============================================================

    /**
     * @param int[] $userIds
     */
    protected function assignAllRolesTo(array $userIds): void
    {
        $roles = Role::query()->get();

        if ($roles->isEmpty()) {
            $this->command?->warn('UsersSeeder: roles table is empty, skipping role assignment.');
            return;
        }

        foreach ($userIds as $id) {
            $user = User::query()->find($id);

            if (!$user) {
                $this->command?->warn("UsersSeeder: user {$id} not found, skipping.");
                continue;
            }

            // assignRole идемпотентен: уже выданные роли не дублируются,
            // «чужие» не удаляются. Ровно то, что нужно для «добавить все».
            $user->assignRole($roles);
        }
    }

    // ============================================================
    // Sequence
    // ============================================================

    /**
     * После вставки явных id (1, 3) PostgreSQL-последовательность
     * `users_id_seq` остаётся на 1, и следующий обычный INSERT упадёт
     * с `duplicate key`. Сдвигаем её до MAX(id).
     */
    protected function resetUsersSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $maxId = (int) DB::table('users')->max('id');
        if ($maxId < 1) {
            return;
        }

        try {
            // pg_get_serial_sequence вернёт имя sequence для bigserial/bigint
            // с default nextval(...). Для $table->id() в Laravel это bigserial.
            DB::statement(
                "SELECT setval(
                    pg_get_serial_sequence('users', 'id'),
                    (SELECT MAX(id) FROM users)
                )"
            );
        } catch (\Throwable $e) {
            // На всякий случай — если колонка не serial, а identity
            // или схема нестандартная, не роняем сидер.
            $this->command?->warn(
                'UsersSeeder: could not reset users sequence: ' . $e->getMessage()
            );
        }
    }
}
