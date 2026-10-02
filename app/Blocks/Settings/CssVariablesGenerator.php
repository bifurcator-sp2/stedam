<?php

namespace App\Blocks\Settings;

class CssVariablesGenerator
{
    public static function generate(): string
    {
        $lines = [':root {'];

        foreach (ColorPalette::all() as $name => $channels) {
            foreach ($channels['light'] as $channel => $hex) {
                $lines[] = sprintf('    --%s-%s: %s;', $channel, $name, $hex);
            }
        }

        $lines[] = '}';
        $lines[] = '';
        $lines[] = '.dark {';

        foreach (ColorPalette::all() as $name => $channels) {
            foreach ($channels['dark'] as $channel => $hex) {
                $lines[] = sprintf('    --%s-%s: %s;', $channel, $name, $hex);
            }
        }

        $lines[] = '}';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public static function write(string $path): void
    {
        file_put_contents($path, self::generate());
    }
}
