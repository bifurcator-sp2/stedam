<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::prepareFakeDisks();

        parent::setUp();
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
