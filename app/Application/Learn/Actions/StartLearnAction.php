<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Concerns\DrainsLearnEvents;
use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\LearnSeed;
use App\Application\Learn\DTOs\StartLearnData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LearnQuestionsBuilderInterface;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\LessonRepositoryInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\LearnQuestions;

final readonly class StartLearnAction
{
    use DrainsLearnEvents;

    public function __construct(
        private LearnRepositoryInterface $learns,
        private LessonRepositoryInterface $lessons,
        private SnapshotResolverInterface $snapshots,
        private LearnQuestionsBuilderInterface $questionsBuilder,
        private StatusLoggerInterface $statusLogger,
        private ContextResolverInterface $contextResolver,
        private Clock $clock,
    ) {}

    public function __invoke(StartLearnData $data): Learn
    {
        // 1. Право на прохождение — по контексту смотрящего.
        $context = $this->contextResolver->resolve($data->userId);
        if (!$context->canStart) {
            throw new LearnAccessDenied('You are not allowed to start a learn');
        }

        // 2. Урок существует и опубликован
        if (!$this->lessons->isPublished($data->lessonId)) {
            throw new LessonNotFound(
                "Lesson {$data->lessonId->value} is not published"
            );
        }

        $now = $this->clock->now();

        // 3. Идемпотентность: если есть активное прохождение — работать с ним.
        $existing = $this->learns->findActiveByUserAndLesson(
            $data->userId,
            $data->lessonId,
        );
        if ($existing !== null) {
            // Уже идёт — повторный старт ничего не меняет (в т.ч. не
            // перегенерирует questions: ученик видит ту же серию задач).
            if ($existing->isInProgress()) {
                return $existing;
            }

            // created/scheduled → старт: снапшот + уникальные задачи для
            // этого learnId. Сид привязан к существующему learnId, поэтому
            // повторный start после cancel вернёт ту же серию.
            $learnId   = $existing->id();
            $questions = $this->buildQuestions($data, $learnId);

            $existing->start(
                $this->snapshots->ensureFor($data->lessonId),
                $questions,
                $now,
            );

            $existing = $this->learns->save($existing);
            $this->drainLearnEvents($existing);

            return $existing;
        }

        // 4. Резолвим снапшот (Redis → S3 → генерация под lock).
        //    Снапшот НЕ содержит текстов заданий — только структуру.
        $snapshot = $this->snapshots->ensureFor($data->lessonId);

        // 5. Резервируем learnId до генерации: сид = f(userId, learnId, blockIndex).
        $learnId = $this->learns->reserveId();

        // 6. Генерируем уникальные задачи для этого прохождения.
        $questions = $this->buildQuestions($data, $learnId);

        // 7. Домен создаёт прохождение.
        $learn = Learn::startNow(
            userId: $data->userId,
            lessonId: $data->lessonId,
            snapshot: $snapshot,
            questions: $questions,
            now: $now,
        )->withId($learnId);

        // 8. Синхронный INSERT с предвыделенным id.
        $learn = $this->learns->insert($learn);

        // 9. Начальный статус нового прохождения: домен его не порождает
        //    (id появляется только после insert), поэтому отправляем вручную
        //    через тот же трейт — аудит и затем шина (§17.5).
        $this->drainInitialStatus($learn);

        // 10. Доменные события перехода (created/scheduled → in_progress).
        //     У нового агрегата буфер пуст, но вызов оставлен для симметрии.
        $this->drainLearnEvents($learn);

        return $learn;
    }

    private function buildQuestions(StartLearnData $data, \App\Domain\Learn\ValueObjects\LearnId $learnId): LearnQuestions
    {
        return $this->questionsBuilder->buildFor(
            new LearnSeed(userId: $data->userId, learnId: $learnId),
            $data->lessonId,
        );
    }
}
