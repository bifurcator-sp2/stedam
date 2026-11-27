<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\User;
use App\Models\UserData;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Рандомное создание/заполнение user_data существующих пользователей.
 *
 * Новых пользователей НЕ создаёт: берёт диапазон существующих users по id
 * и для каждого рандомно создаёт или заполняет user_data (ФИО, год рождения,
 * пол, страна).
 *
 * Запуск:
 *   ./vendor/bin/sail artisan users:seed-data
 *   ./vendor/bin/sail artisan users:seed-data --from=1 --to=2000 --chunk=200
 */
final class SeedUserDataCommand extends Command
{
    protected $signature = 'users:seed-data
        {--from=1 : Первый id пользователя диапазона (включительно)}
        {--to=0 : Последний id диапазона (включительно; 0 — до максимального id)}
        {--chunk=200 : Батч-транзакции: один commit на N записей}
        {--force : Не спрашивать подтверждения}';

    protected $description = 'Рандомно создаёт/заполняет user_data для пользователей из диапазона id';

    public function handle(): int
    {
        $from  = (int) $this->option('from');
        $to    = (int) $this->option('to');
        $chunk = (int) $this->option('chunk');

        if ($from < 0) {
            $this->error('--from должен быть >= 0.');
            return self::FAILURE;
        }

        if ($chunk < 1) {
            $this->error('--chunk должен быть >= 1.');
            return self::FAILURE;
        }

        $countryIds = $this->resolveCountryIds();
        if ($countryIds === []) {
            $this->error('В таблице countries нет записей. Сначала выполните CountrySeeder.');
            return self::FAILURE;
        }

        $usersQuery = User::query()->select('id')->where('id', '>=', $from);

        if ($to > 0) {
            $usersQuery->where('id', '<=', $to);
        }

        $total = (clone $usersQuery)->count();

        if ($total === 0) {
            $this->warn('В заданном диапазоне id нет пользователей. Ничего не делаю.');
            return self::SUCCESS;
        }

        $this->line("Заполняю user_data для {$total} пользователей (диапазон id: {$from}.." . ($to > 0 ? $to : 'max') . ", chunk={$chunk}).");

        if (! $this->option('force') && ! $this->confirm('Продолжить?', true)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        $faker = FakerFactory::create('ru_RU');
        $now = now();
        $processed = 0;

        $usersQuery->chunkById($chunk, function (Collection $users) use ($countryIds, $faker, $now, &$processed) {
            $rows = [];

            foreach ($users as $user) {
                $rows[] = $this->randomUserDataRow((int) $user->id, $countryIds, $faker, $now);
            }

            // user_id уникален: upsert обновит существующую запись, иначе создаст.
            UserData::query()->upsert($rows, ['user_id'], [
                'first_name',
                'last_name',
                'middle_name',
                'birth_year',
                'gender',
                'direction',
                'country_id',
                'updated_at',
            ]);

            $processed += count($rows);
            $this->output->write('.');
        });

        $this->newLine(2);
        $this->info("Готово: обработано {$processed} пользователей.");

        return self::SUCCESS;
    }

    /**
     * @param list<int> $countryIds
     * @return array<string, mixed>
     */
    private function randomUserDataRow(int $userId, array $countryIds, Generator $faker, \DateTimeInterface $now): array
    {
        $gender = $faker->randomElement(['male', 'female']);

        return [
            'user_id'     => $userId,
            'first_name'  => $faker->firstName($gender),
            'last_name'   => $faker->lastName($gender),
            'middle_name' => $this->middleName($faker, $gender),
            'birth_year'  => $faker->numberBetween(1950, 2015),
            'gender'      => $gender,
            'direction'   => 'ltr',
            'country_id'  => $faker->randomElement($countryIds),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
    }

    /**
     * Отчество: у Faker нет отдельного генератора — «случайное имя + ович/овна».
     */
    private function middleName(Generator $faker, string $gender): string
    {
        $base = $faker->firstName($gender);

        return $gender === 'female'
            ? $base.'овна'
            : $base.'ович';
    }

    /**
     * @return list<int>
     */
    private function resolveCountryIds(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->orWhereNull('is_active')
            ->pluck('id')
            ->all();
    }
}
