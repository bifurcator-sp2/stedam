<?php

namespace App\Blocks\Settings;

class SettingDefinition
{

    public function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public mixed $default = null,
        public ?array $allowed = null,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly bool $required = false,
        public readonly ?array $children = null,
    ) {}

    public function isContainer(): bool
    {
        return in_array($this->type, [SettingType::Object, SettingType::Array], true);
    }

    public function isLeaf(): bool
    {
        return ! $this->isContainer();
    }

}
