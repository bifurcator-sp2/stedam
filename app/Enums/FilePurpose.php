<?php
// app/Enums/FilePurpose.php

namespace App\Enums;

enum FilePurpose: string
{
case Avatar = 'avatar';
case Cover = 'cover';
case BackgroundImage = 'background-image';
case Gallery1 = 'gallery1';
case Gallery2 = 'gallery2';

    /**
     * Все значения для валидации.
     *
     * @return string[]
     */
    public static function values(): array
{
    return array_map(fn (self $c) => $c->value, self::cases());
}

    /**
     * Человекочитаемое название.
     */
    public function label(): string
{
    return match ($this) {
        self::Avatar => 'Аватар',
        self::Cover => 'Обложка',
        self::BackgroundImage => 'Фоновое изображение',
        self::Gallery1 => 'Галерея 1',
        self::Gallery1 => 'Галерея 2',
    };
}
}
