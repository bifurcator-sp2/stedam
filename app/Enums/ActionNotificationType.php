<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Типы-«действия» внутри стандартных (database) уведомлений Laravel.
 *
 * ВНИМАНИЕ: это НЕ тип класса уведомления (для этого — NotificationType,
 * где ключ = короткое имя класса). Здесь — `data.type` в payload'е
 * уведомления RequestedActions, описывающий СМЫСЛ сообщения:
 * «запрос», «принято», «отклонено», «выполнено», «ошибка».
 *
 * Значение кейса совпадает с `data.type` в таблице `notifications`
 * (см. RequestedActionNotification и сообщения handler'ов RequestedActions).
 *
 * В API отдаётся как `action_types` (справочник value → локализованное
 * название). Ключи переводов — `notifications.action_types.*`.
 */
enum ActionNotificationType: string
{
    case Request  = 'request';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Done     = 'done';
    case Error    = 'error';

    /**
     * Локализованное название типа.
     */
    public function label(): string
    {
        return __('notifications.action_types.'.$this->value);
    }

    /**
     * Справочник типов: value => локализованное название.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
