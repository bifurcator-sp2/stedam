<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Application\Learn\DTOs\RawAnswer;
use App\Domain\Learn\ValueObjects\AnswerImage;
use App\Domain\Learn\ValueObjects\LearnId;

/**
 * Хранилище изображений-ответов ученика (публичный S3).
 *
 * Application не знает про S3/шардирование: передаёт сырые изображения,
 * получает ссылки. Реализация отвечает за ресайз, конвертацию и раскладку.
 */
interface AnswerStorageInterface
{
    /**
     * Сохраняет изображения ответа в шардированную структуру и возвращает ссылки.
     *
     * Семантика — полная замена набора ответов: ранее сохранённые файлы
     * прохождения удаляются перед записью новых.
     *
     * @param list<RawAnswer> $answers
     * @return list<AnswerImage> упорядочено по index
     */
    public function replaceAll(LearnId $learnId, array $answers): array;

    /**
     * Публичный URL для ссылки-ответа (для отдачи фронту/учителю).
     */
    public function urlFor(AnswerImage $image): string;
}
