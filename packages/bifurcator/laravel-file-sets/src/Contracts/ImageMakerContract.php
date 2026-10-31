<?php

namespace Bifurcator\FileSets\Contracts;

/**
 * Обработчик «основных» изображений (ресайз + перекодирование).
 *
 * В отличие от ThumbnailMakerContract, обычно работает IN-PLACE —
 * перезаписывает файл по тому же пути. Имя не меняется, поэтому
 * возвращает basename($sourcePath) при успехе и null при
 * ошибке/пропуске (не картинка, SVG, уже в пределах лимита).
 */
interface ImageMakerContract
{
    public function make(string $sourcePath, ?FileStorage $storage = null): ?string;
}
