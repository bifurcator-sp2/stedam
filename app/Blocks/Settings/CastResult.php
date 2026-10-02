<?php

namespace App\Blocks\Settings;

class CastResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $value = null,
        public readonly ?string $reason = null,
    ) {}

public static function ok(mixed $value): self
{
    return new self(true, $value);
}

public static function fail(?string $reason = null): self
{
    return new self(false, null, $reason);
}
}
