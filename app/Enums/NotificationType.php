<?php

declare(strict_types=1);

namespace App\Enums;

use App\Notifications\RequestedActions\RequestedActionNotification;

/**
 * Реестр типов уведомлений: связка «класс уведомления → короткое имя + перевод».
 *
 * ============================================================
 *  ЗАЧЕМ
 * ============================================================
 *
 * Таблица `notifications` (канал database) хранит в колонке `type` FQCN
 * класса-уведомления, например:
 *     App\Notifications\RequestedActions\RequestedActionNotification
 *
 * Отдавать это наружу нельзя: это внутренняя структура проекта (фронт не
 * должен знать про namespace'ы и Laravel). Поэтому API отдаёт:
 *   - в каждом элементе `type` — КОРОТКОЕ имя класса (basename):
 *       RequestedActionNotification
 *   - в `types` ответа — справочник short name → локализованный заголовок:
 *       { "RequestedActionNotification": "Запрос на подтверждение действия" }
 *
 * ============================================================
 *  КАК ДОБАВИТЬ НОВОЕ УВЕДОМЛЕНИЕ
 * ============================================================
 *
 *  1. Добавить кейс в этот enum: case Xxx = SomeNotification::class;
 *  2. Добавить перевод в lang/{ru,en}/notifications.php → 'notifications' =>
 *     [ 'Xxx' => '...' ].
 *
 * Кэш соответствия «FQCN → short name» строится один раз за запрос через
 * map(); искать по короткому имени не нужно — short — это basename FQCN,
 * он и так однозначен для наших классов.
 */
enum NotificationType: string
{
    // FQCN уходит в value; короткое имя = имя кейса.
    case RequestedAction = RequestedActionNotification::class;

    /**
     * Короткое имя класса уведомления (basename FQCN).
     *
     * Именно оно отдаётся в `data.*.type` и является ключом справочника `types`.
     * Совпадает с именем кейса — так удобнее читать глазами.
     */
    public function shortName(): string
    {
        return $this->name;
    }

    /**
     * Локализованный заголовок типа.
     *
     * Ключ перевода — short name: `notifications.types.RequestedActionNotification`.
     */
    public function label(): string
    {
        return __('notifications.types.'.$this->shortName());
    }

    /**
     * Найти тип по FQCN из БД (колонка notifications.type).
     *
     * Возвращает null для незнакомых классов (например, служебные mail-only
     * уведомления, которые не попадают в database-канал).
     */
    public static function fromClass(?string $fqcn): ?self
    {
        if ($fqcn === null || $fqcn === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->value === $fqcn) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Нормализовать FQCN из БД в короткое имя для API.
     *
     * Незнакомый класс деградирует до basename без применения перевода —
     * фронт не 500'ит, но и внутренний namespace не утекает.
     */
    public static function shortNameOf(?string $fqcn): ?string
    {
        if ($fqcn === null || $fqcn === '') {
            return null;
        }

        return self::fromClass($fqcn)?->shortName()
            ?? self::basename($fqcn);
    }

    /**
     * Справочник для ответа API: short name → локализованный заголовок.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->shortName()] = $case->label();
        }

        return $labels;
    }

    /**
     * Дополнить справочник `types` реально встреченными, но не зарегистрированными
     * классами — чтобы фронт получал перевод-заглушку (сам short name) и
     * справочник всегда покрывал все элементы страницы.
     *
     * @param  iterable<string|null>  $fqcns
     * @return array<string, string>
     */
    public static function labelsCovering(iterable $fqcns): array
    {
        $labels = self::labels();

        foreach ($fqcns as $fqcn) {
            $short = self::shortNameOf($fqcn);

            if ($short !== null && ! array_key_exists($short, $labels)) {
                $labels[$short] = $short;
            }
        }

        return $labels;
    }

    private static function basename(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
