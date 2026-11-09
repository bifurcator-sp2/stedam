<?php

declare(strict_types=1);

use App\Domain\Learn\Exceptions\InvalidSnapshotHash;
use App\Domain\Learn\ValueObjects\SnapshotHash;

it('accepts a valid sha-256 hex hash', function () {
    $hash = new SnapshotHash(str_repeat('a', 64));
    expect($hash->value)->toBe(str_repeat('a', 64));
});

it('rejects invalid hashes', function (string $invalid) {
    expect(fn () => new SnapshotHash($invalid))->toThrow(InvalidSnapshotHash::class);
})->with([
    'too short'      => ['abc'],
    'too long'       => [str_repeat('a', 65)],
    'uppercase'      => [str_repeat('A', 64)],
    'non-hex letter' => [str_repeat('z', 64)],
    'with prefix'    => ['sha256:' . str_repeat('a', 64)],
    'empty'          => [''],
]);

it('computes sha-256 from content', function () {
    $hash = SnapshotHash::fromContent('some json');
    expect($hash->value)->toBe(hash('sha256', 'some json'))
        ->and(strlen($hash->value))->toBe(64);
});

it('shards by prefix', function () {
    $hash = new SnapshotHash(str_repeat('a', 64));
    expect($hash->shard(2))->toBe('aa')
        ->and($hash->shard(4))->toBe('aaaa');
});

it('compares by value', function () {
    $a = SnapshotHash::fromContent('x');
    $b = SnapshotHash::fromContent('x');
    $c = SnapshotHash::fromContent('y');

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

it('casts to string', function () {
    $hash = SnapshotHash::fromContent('payload');
    expect((string) $hash)->toBe(hash('sha256', 'payload'));
});
