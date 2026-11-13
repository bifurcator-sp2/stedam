<?php

declare(strict_types=1);

use App\Application\Shared\TransactionManagerInterface;
use App\Infrastructure\Shared\EloquentTransactionManager;
use Illuminate\Support\Facades\DB;

/**
 * Проверка, что порт транзакций действительно связан с Laravel и что
 * откат работает. Без RefreshDatabase: транзакция в тесте откатывается
 * сама, состояние БД не нужно.
 */
it('резолвится из контейнера как реализация порта', function () {
    $manager = app(TransactionManagerInterface::class);

    expect($manager)->toBeInstanceOf(EloquentTransactionManager::class);
});

it('возвращает результат операции', function () {
    $manager = app(TransactionManagerInterface::class);

    $result = $manager->transactional(fn (): int => 42);

    expect($result)->toBe(42);
});

it('фиксирует изменения при успехе', function () {
    $manager = app(TransactionManagerInterface::class);

    $levelBefore = DB::transactionLevel();

    $manager->transactional(function (): void {
        // Внутри транзакции уровень поднялся.
        expect(DB::transactionLevel())->toBeGreaterThan(0);
    });

    // После — вернулся к исходному.
    expect(DB::transactionLevel())->toBe($levelBefore);
});

it('откатывает изменения при исключении', function () {
    $manager = app(TransactionManagerInterface::class);

    $thrown = false;

    try {
        $manager->transactional(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue()
        ->and(DB::transactionLevel())->toBe(0);
});

it('пробрасывает исходное исключение, не подменяя его', function () {
    $manager = app(TransactionManagerInterface::class);

    expect(
        fn () => $manager->transactional(function (): void {
            throw new DomainException('доменная ошибка');
        }),
    )->toThrow(DomainException::class, 'доменная ошибка');
});
