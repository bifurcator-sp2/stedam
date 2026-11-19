<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\LearnSnapshotData;
use App\Application\Learn\Exceptions\SnapshotUnavailable;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\Exceptions\SnapshotMissing;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Снапшот конкретного прохождения: версия, на которой учился ученик.
 *
 * Снапшот неизменяем и контент-адресован (learns.lesson_snapshot — его SHA-256),
 * поэтому берём сохранённый JSON по хэшу, а не собираем заново из текущей
 * структуры урока (она могла измениться после старта).
 *
 * Самовосстановление: если файл снапшота удалён из хранилища, но урок с тех
 * пор не менялся (пересобранный снапшот даёт тот же хэш) — файл пересоздаётся
 * и отдаётся обычным способом. Если содержимое урока изменилось — бросаем
 * SnapshotUnavailable: отдать «другую» версию нельзя.
 *
 * @throws LearnNotFound если прохождения нет или оно вне scope смотрящего
 * @throws SnapshotUnavailable если снапшот ещё не зафиксирован (created/scheduled)
 *         или его содержимое недоступно в хранилище и не может быть восстановлено
 */
final readonly class GetLearnSnapshotAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private SnapshotReaderInterface $snapshots,
        private SnapshotResolverInterface $resolver,
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
            snapshotData: $this->read($hash),
        ))->withQuestionsFrom($learn->questions());
    }

    private function read(SnapshotHash $hash): string
    {
        try {
            return $this->snapshots->read($hash);
        } catch (SnapshotUnavailable $e) {
            return $this->restore($hash) ?? throw $e;
        }
    }

    /**
     * Пересоздаёт удалённый файл снапшота. Возвращает содержимое, если урок
     * с момента старта не менялся, и null — если снапшот уже невосстановим.
     */
    private function restore(SnapshotHash $hash): ?string
    {
        try {
            $lessonId = $this->resolver->lessonFor($hash);
            $rebuilt = $this->resolver->forceEnsureFor($lessonId);

            // Совпал хэш — урок неизменен, файл действительно восстановлен.
            return $rebuilt->equals($hash) ? $this->snapshots->read($hash) : null;
        } catch (SnapshotMissing) {
            // Прохождения со ссылкой на снапшот больше нет — восстанавливать нечем.
            return null;
        }
    }
}
