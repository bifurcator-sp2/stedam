<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::prepareFakeDisks();

        parent::setUp();

        $this->guardAgainstProductionDatabase();
    }

    /**
     * Рабочие базы, по которым прогонять тесты запрещено. Список закрытый:
     * любая база вне него считается рабочей.
     */
    protected const TESTING_ALLOWED_DATABASES = ['testing', 'testing_*'];

    /**
     * Защита от прогона тестов по рабочей БД: 26/27.11.2026 `php artisan test`
     * c рабочим .env (DB_DATABASE=laravel) через RefreshDatabase сносил базу
     * laravel, а свежие дампы делают эту потерю невосстановимой.
     *
     * Проверяем именно подключение по умолчанию, а не env: phpunit.xml задаёт
     * DB_DATABASE=testing, но при запуске на хосте переменные .env могут
     * перекрывать его. Это должно совпадать с основным гейтом приложения
     * (app/Support/Testing/EnsureTestingDatabase).
     */
    protected function guardAgainstProductionDatabase(): void
    {
        $default = (string) config('database.default');

        // В тестах Laravel переключает соединение по умолчанию на `testing`
        // (phpunit.xml: DB_CONNECTION=testing). Если такого соединения нет,
        // значит переключения не было — тесты пойдут по рабочему соединению.
        // Проверяем оба: гейт должен срабатывать при любом рабочем соединении.
        $targets = array_values(array_unique([
            (string) (getenv('DB_CONNECTION') ?: env('DB_CONNECTION')),
            $default,
        ]));

        foreach ($targets as $target) {
            if ($target === '') {
                continue;
            }

            $this->guardConnection($target);
        }
    }

    /**
     * Отдельное соединение-цель: если оно есть в конфиге и это SQL-база,
     * название базы должно входить в TESTING_ALLOWED_DATABASES.
     */
    private function guardConnection(string $target): void
    {
        if (config("database.connections.{$target}") === null) {
            return;
        }

        $driver = (string) config("database.connections.{$target}.driver");

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true)) {
            return;
        }

        $database = (string) config("database.connections.{$target}.database");

        foreach (self::TESTING_ALLOWED_DATABASES as $allowed) {
            $matches = str_ends_with($allowed, '*')
                ? str_starts_with($database, rtrim($allowed, '*'))
                : $database === $allowed;

            if ($matches) {
                return;
            }
        }

        throw new \RuntimeException(
            "Тесты подключены к базе «{$database}» (соединение {$target}), а разрешены только: "
            .implode(', ', self::TESTING_ALLOWED_DATABASES)
            .'. Прогон отменён, чтобы тесты не стёрли данные. '
            .'Запускайте тесты в контейнере: docker exec stedam-laravel.test-1 php artisan test'
        );
    }

    /**
     * Storage::fake() очищает корень диска через cleanDirectory(), но не меняет
     * его права. Каталоги дисков (0700), оставшиеся от другого uid (в Sail php
     * запускается под 1337, при ручном artisan — под root), потом нельзя даже
     * прочитать, и cleanDirectory() падает с Permission denied. Смена владельца
     * и chmod на каталогах, примонтированных из WSL, работают непредсказуемо.
     *
     * Поэтому права корня делаем открытыми (0777), а диски, оставшиеся не от
     * текущего uid, удаляем: для этого достаточно прав на запись в корень, а
     * создаст их заново Storage::fake() уже под текущим uid.
     *
     * static и вызов до parent::setUp(): используется в ->beforeEach(),
     * который Pest выполняет раньше setUp().
     */
    public static function prepareFakeDisks(): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $root = dirname(__DIR__).'/storage/framework/testing/disks';

        if (! is_dir($root)) {
            @mkdir($root, 0777, true);
        }

        @chmod($root, 0777);

        if (! is_writable($root)) {
            throw new \RuntimeException(
                "Тестовые диски недоступны: {$root} принадлежит uid ".fileowner($root)
                .'. Выполните: docker compose exec -T laravel.test chown -R sail:sail storage/framework/testing'
            );
        }

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $disk) {
            if ($uid !== null && fileowner($disk) === $uid) {
                continue;
            }

            self::removeDirectory($disk);

            // На монтированиях WSL права и владелец каталогов ведут себя
            // непредсказуемо: каталог может остаться чужим. Тогда Storage::fake()
            // ждёт от него только пустоту, поэтому очищаем содержимое и права.
            if (is_dir($disk)) {
                @chmod($disk, 0777);
                self::removeDirectory($disk, keepRoot: true);
            }
        }
    }

    private static function removeDirectory(string $path, bool $keepRoot = false): void
    {
        foreach (glob($path.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $item) {
            is_dir($item) && ! is_link($item) ? self::removeDirectory($item) : @unlink($item);
        }

        if (! $keepRoot) {
            @rmdir($path);
        }
    }
}
