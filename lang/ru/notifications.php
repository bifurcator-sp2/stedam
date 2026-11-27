<?php

return [
    // Тип уведомления по короткому имени класса (App\Enums\NotificationType).
    // Ключ — имя кейса enum (shortName), которое отдаётся в API.
    'types' => [
        'RequestedAction' => 'Запрос на подтверждение действия',
    ],

    // data.type внутри уведомления (App\Enums\ActionNotificationType).
    'action_types' => [
        'request'  => 'Запрос',
        'approved' => 'Запрос принят',
        'rejected' => 'Запрос отклонён',
        'done'     => 'Действие выполнено',
        'error'    => 'Ошибка запроса',
    ],

    // Типы заявок по alias обработчика (config/requested_actions.php).
    'requested_action_types' => [
        'StudentToTeacher' => 'Запись к преподавателю',
    ],

    // Статусы заявки (enum RequestedActionStatus).
    'statuses' => [
        'created'  => 'Создана',
        'pending'  => 'На рассмотрении',
        'approved' => 'Одобрена',
        'rejected' => 'Отклонена',
        'done'     => 'Выполнена',
        'error'    => 'Ошибка',
    ],
];
