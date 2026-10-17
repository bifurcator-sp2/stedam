<?php
// packages/bifurcator/laravel-file-sets/src/FileStorageKeys.php

namespace Bifurcator\FileSets;

/**
 * Ключи биндингов FileStorage в контейнере.
 *
 * Используются вместо магических строк 'file-sets.storage.temp' / 'file-sets.storage.store'.
 */
final class FileStorageKeys
{
    public const TEMP  = 'file-sets.storage.temp';
    public const STORE = 'file-sets.storage.store';
}
