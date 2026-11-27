<?php

namespace App\Support\Testing;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Гейт для `artisan test --env=...` / `pest --env=...`: если тесты запущены
 * с рабочим окружением, RefreshDatabase сотрёт рабочую базу (так
 * 26–27.11.2026 потерялись данные laravel).
 *
 * Обычный `artisan test` (Collision) вычищает переменные из .env и отдаёт
 * дочернему phpunit собственное окружение с DB_DATABASE=testing из
 * phpunit.xml, поэтому рабочую базу он не трогает. А с явным --env=...
 * .env не вычищается, и phpunit наследует рабочее соединение — этот случай
 * и проверяем.
 *
 * Регистрируется в AppServiceProvider::boot() через Event::listen.
 */
class EnsureTestingDatabase
{
    public const ALLOWED_DATABASES = ['testing', 'testing_*'];

    public const TEST_COMMANDS = ['test', 'pest'];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::TEST_COMMANDS, true)) {
            return;
        }

        // `artisan test` без --env подменяет окружение на тестовое сам.
        if (! $event->input->hasParameterOption('--env', true)) {
            return;
        }

        $this->assertTestingDatabase();
    }

    public function listen(CommandStarting $event): void
    {
        $this->handle($event);
    }

    /**
     * Бросает RuntimeException, если соединение по умолчанию смотрит не в
     * тестовую базу.
     */
    public function assertTestingDatabase(?string $connection = null, ?string $database = null): void
    {
        $connection ??= (string) config('database.default');
        $database ??= (string) config("database.connections.{$connection}.database");
        $driver = (string) config("database.connections.{$connection}.driver");

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true)) {
            return;
        }

        foreach (self::ALLOWED_DATABASES as $allowed) {
            if ($database === $allowed) {
                return;
            }

            if (str_ends_with($allowed, '*') && str_starts_with($database, rtrim($allowed, '*'))) {
                return;
            }
        }

        throw new RuntimeException(
            "Тесты запрещены: подключение «{$connection}» смотрит в базу «{$database}», "
            .'а разрешены только '.implode(', ', self::ALLOWED_DATABASES).'. '
            .'Это рабочая база - RefreshDatabase её сотрёт. Запускайте тесты в контейнере: '
            .'docker exec stedam-laravel.test-1 php artisan test'
        );
    }
}
