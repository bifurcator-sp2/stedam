<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Learn\Ports\LearnAnswersWriterInterface;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Models\Learn as LearnModel;

/**
 * Eloquent-реализация записи сырых ответов в learns.answers (jsonb).
 *
 * Отдельно от EloquentLearnRepository: репозиторий маппит доменный
 * LearnAnswers (изображения {index, file}), а здесь — фронтенд-структура
 * штрихов, которую домен не интерпретирует.
 */
final class EloquentLearnAnswersWriter implements LearnAnswersWriterInterface
{
    public function replaceRawAnswers(LearnId $learnId, array $answers): void
    {
        $model = LearnModel::query()->find($learnId->value);

        if ($model === null) {
            throw new LearnNotFound("Learn {$learnId->value} not found");
        }

        $model->answers = $answers;
        $model->save();
    }
}
