<?php

namespace App\Blocks\Settings;

class SettingsSynchronizer
{
    public static function forForm(?string $code, ?array $existing): array
    {
        $existing ??= [];

        if (! $code || ! BlockSettingsRegistry::has($code)) {
            return $existing;
        }

        return SettingsNormalizer::normalize($code, $existing);
    }

    public static function forSave(?string $code, ?array $input): array
    {
        $input ??= [];

        if (! $code || ! BlockSettingsRegistry::has($code)) {
            return $input;
        }

        return SettingsNormalizer::normalize($code, $input);
    }
}
