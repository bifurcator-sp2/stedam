<?php

namespace App\Blocks\Settings;

class SettingsNormalizer
{

    public static function normalize(string $code, array $input): array
    {
        return BlockSettingsRegistry::for($code);
    }




}
