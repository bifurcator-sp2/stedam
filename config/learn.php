<?php

declare(strict_types=1);

/**
 * Настройки контекста Learn.
 *
 * Здесь — управляемые критерии системной архивации прохождений.
 * Всё переопределяется через .env без деплоя.
 */
return [
    /*
    | Логгер смены статусов (learn_statuses).
    |
    | driver = 'redis' — быстрый буфер в Redis, затем батч-сброс в БД
    |                    (FlushLearnStatusBufferJob / learn:flush-statuses).
    | driver = 'sync'  — синхронная запись в БД (DbStatusLogger), без буфера.
    */
    'status_logger' => [
        'driver'      => env('LEARN_STATUS_LOGGER', 'redis'),
        'connection'  => env('LEARN_STATUS_REDIS_CONNECTION', 'default'),
        'buffer_key'  => env('LEARN_STATUS_BUFFER_KEY', 'learn:status:buffer'),
        'flush_chunk' => (int) env('LEARN_STATUS_FLUSH_CHUNK', 1000),
    ],

    /*
    | Резолвер снапшота урока (для start).
    |
    | Диск S3 (приватный), кэш lessonId→hash в Redis, distributed lock
    | на генерацию.
    */
    'snapshot' => [
        // driver: 's3' — боевой (Redis+S3+lock), 'hash' — лёгкий (только Redis).
        'driver'      => env('LEARN_SNAPSHOT_DRIVER', 's3'),
        'disk'        => env('LEARN_SNAPSHOT_DISK', 's3_private'),
        'prefix'      => env('LEARN_SNAPSHOT_PREFIX', 'learns/snapshots'),
        'cache_ttl'   => (int) env('LEARN_SNAPSHOT_CACHE_TTL', 3600),
        'lock_ttl'    => (int) env('LEARN_SNAPSHOT_LOCK_TTL', 10),
        'lock_wait'   => (int) env('LEARN_SNAPSHOT_LOCK_WAIT', 5),
    ],

    /*
    | Ответы ученика — изображения (письменные ответы «пером»).
    |
    | Хранятся в публичном S3 в шардированной структуре под корневой папкой:
    |   {prefix}/{learnId % 1000:03}/{learnId}/{index}.{ext}
    |
    | Обработка изображения настраивается тремя независимыми флагами:
    |
    |   transcode        — главный выключатель. false = файл сохраняется
    |                      байт-в-байт, без декодирования/перекодирования;
    |                      расширение берётся из исходника (по mime).
    |   resize           — масштабировать по большой стороне до max_side
    |                      (без апскейла). Действует только при transcode=true.
    |   convert_to_webp  — перекодировать в webp. false = сохранять в исходном
    |                      формате после ресайза. Действует при transcode=true.
    |
    | quality — качество webp (0..100). max_bytes/allowed_mimes — валидация входа.
    | Последнее автосохранение перезаписывает файл.
    */
    'answers' => [
        'disk'            => env('LEARN_ANSWERS_DISK', 's3_public'),
        'prefix'          => env('LEARN_ANSWERS_PREFIX', 'answers'),

        // Главный выключатель обработки. false → pass-through (как прислали).
        'transcode'       => (bool) env('LEARN_ANSWERS_TRANSCODE', true),

        // Ресайз по большой стороне (только при transcode=true).
        'resize'          => (bool) env('LEARN_ANSWERS_RESIZE', true),
        'max_side'        => (int) env('LEARN_ANSWERS_MAX_SIDE', 1024),

        // Конвертация в webp (только при transcode=true).
        'convert_to_webp' => (bool) env('LEARN_ANSWERS_CONVERT_TO_WEBP', true),
        'quality'         => (int) env('LEARN_ANSWERS_QUALITY', 80),

        'max_bytes'       => (int) env('LEARN_ANSWERS_MAX_BYTES', 10 * 1024 * 1024),

        // Разрешённые mime, через запятую в env:
        //   LEARN_ANSWERS_ALLOWED_MIMES=image/png,image/jpeg,image/webp
        'allowed_mimes'   => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'LEARN_ANSWERS_ALLOWED_MIMES',
                'image/png,image/jpeg,image/webp,image/heic,image/bmp',
            )),
        ))),
    ],

    'archive' => [
        // Глобальный выключатель архивации.
        'enabled' => (bool) env('LEARN_ARCHIVE_ENABLED', true),

        // Сколько записей обрабатывать за один батч.
        'chunk' => (int) env('LEARN_ARCHIVE_CHUNK', 500),

        // Критерии по статусу: сколько дней держать прохождение в этом
        // статусе, прежде чем отправить в архив.
        //
        // Отсчёт ведётся от даты, соответствующей статусу (см. <date_field>):
        //   graded    → complete_date (fallback: status_date)
        //   created   → created_at
        //   scheduled → created_at
        //
        // Статусы БЕЗ записи не архивируются:
        //   done        — сдан, ждёт оценки учителя (нельзя терять);
        //   in_progress — ученик может вернуться (не архивируем авто).
        'after_days' => [
            'graded'    => (int) env('LEARN_ARCHIVE_GRADED_DAYS', 365),
            'created'   => (int) env('LEARN_ARCHIVE_CREATED_DAYS', 90),
            'scheduled' => (int) env('LEARN_ARCHIVE_SCHEDULED_DAYS', 90),
        ],
    ],
];
