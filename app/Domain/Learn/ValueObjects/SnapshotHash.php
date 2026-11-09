<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidSnapshotHash;

final readonly class SnapshotHash
{
    private const PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(public string $value)
    {
        if (!preg_match(self::PATTERN, $value)) {
            throw new InvalidSnapshotHash("Invalid SHA-256 hash: {$value}");
        }
    }

    public static function fromContent(string $content): self
    {
        return new self(hash('sha256', $content));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function shard(int $length = 2): string
    {
        return substr($this->value, 0, $length);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
