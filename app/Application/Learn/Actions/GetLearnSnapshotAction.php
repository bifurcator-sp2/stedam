<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\LearnSnapshotData;
use App\Application\Learn\Exceptions\SnapshotUnavailable;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Снапшот конкретного прохождения: версия, на которой учился ученик.
 *
 * Снапшот неизменяем и контент-адресован (learns.lesson_snapshot — его SHA-256),
 * поэтому берём сохранённый JSON по хэшу, а не собираем заново из текущей
 * структуры урока (она могла измениться после старта).
 *
 * @throws LearnNotFound если прохождения нет или оно вне scope смотрящего
 * @throws SnapshotUnavailable если снапшот ещё не зафиксирован (created/scheduled)
 *         или его содержимое недоступно в хранилище
 */
final readonly class GetLearnSnapshotAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private SnapshotReaderInterface $snapshots,
        private ContextResolverInterface $contextResolver,
    ) {}

    public function __invoke(LearnId $learnId, UserId $viewerId): LearnSnapshotData
    {
        $context = $this->contextResolver->resolve($viewerId);

        /** @var Learn|null $learn */
        $learn = $this->learns->findByIdWithinScope($learnId, $context->visibleUserIds());

        if ($learn === null) {
            // Вне scope трактуем как «не найдено», чтобы не раскрывать существование.
            throw new LearnNotFound("Learn {$learnId->value} not found");
        }

        $hash = $learn->snapshot();
        if ($hash === null) {
            throw new SnapshotUnavailable(
                "Learn {$learnId->value} has no snapshot (status {$learn->status()->value})",
            );
        }

        // Снапшот хранит только структуру: тексты заданий живут в самом
        // прохождении (learns.questions) и подмешиваются в proc-блоки.
        return (new LearnSnapshotData(
            learnId: $learnId->value,
            hash: $hash,
            snapshotData: $this->snapshots->read($hash),
        ))->withQuestionsFrom($learn->questions());
    }
}
