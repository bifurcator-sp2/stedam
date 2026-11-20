<?php

namespace App\Infrastructure\Grade;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Рендер рукописных штрихов whiteboard в PNG.
 *
 * Пайплайн: strokes -> SVG -> PNG (rsvg-convert, fallback — ImageMagick if installed),
 * результат кладётся на локальное хранилище в temp/stroke_render под уникальным именем.
 */
class StrokesRender
{
    private const DISK          = 'local';
    private const DIRECTORY     = 'temp/stroke_render';
    private const BACKGROUND    = '#ffffff';
    private const DEFAULT_COLOR = '#000000';
    private const DEFAULT_SIZE  = 2.0;

    /**
     * @param array<int, array<string, mixed>> $strokes массив штрихов (формат JSON whiteboard)
     * @param int                              $whiteboardWidth  ширина холста в CSS-пикселях
     * @param int                              $whiteboardHeight высота холста в CSS-пикселях
     *
     * @return string путь PNG относительно корня диска local
     */
    public function render(array $strokes, int $whiteboardWidth, int $whiteboardHeight): string
    {
        if ($whiteboardWidth <= 0 || $whiteboardHeight <= 0) {
            throw new RuntimeException("Некорректный размер холста: {$whiteboardWidth}x{$whiteboardHeight}");
        }

        $svg = $this->buildSvg($strokes, $whiteboardWidth, $whiteboardHeight);
        $png = $this->rasterize($svg, $whiteboardWidth, $whiteboardHeight);

        $path = self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.png';

        $disk = Storage::disk(self::DISK);

        if (!$disk->put($path, $png)) {
            throw new RuntimeException("Не удалось сохранить PNG в {$path}");
        }

        return $path;
    }

    /**
     * @param array<int, array<string, mixed>> $strokes
     */
    public function buildSvg(array $strokes, int $width, int $height): string
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '"'
            . ' viewBox="0 0 ' . $width . ' ' . $height . '" version="1.1">'
            . '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="' . self::BACKGROUND . '"/>'
            . '<g fill="none" stroke-linecap="round" stroke-linejoin="round">';

        foreach ($strokes as $stroke) {
            if (!is_array($stroke)) {
                continue;
            }

            // TODO: eraser via SVG <mask> (destination-out) — пока штрихи-ластики пропускаются.
            if (!empty($stroke['eraser'])) {
                continue;
            }

            $points = array_values(array_filter(
                is_array($stroke['points'] ?? null) ? $stroke['points'] : [],
                static fn ($p): bool => is_array($p) && is_numeric($p['x'] ?? null) && is_numeric($p['y'] ?? null),
            ));

            if ($points === []) {
                continue;
            }

            $size  = is_numeric($stroke['size'] ?? null) ? (float) $stroke['size'] : self::DEFAULT_SIZE;
            $color = $this->normalizeColor($stroke['color'] ?? null);

            if (count($points) === 1) {
                $svg .= '<circle cx="' . $this->f((float) $points[0]['x'])
                    . '" cy="' . $this->f((float) $points[0]['y'])
                    . '" r="' . $this->f($size / 2)
                    . '" fill="' . $color . '" stroke="none"/>';
                continue;
            }

            $svg .= '<path d="' . $this->buildPath($points)
                . '" stroke="' . $color
                . '" stroke-width="' . $this->f($size) . '"/>';
        }

        return $svg . '</g></svg>';
    }

    /**
     * Сглаживание квадратичными Безье через срединные точки (реплика redrawStroke() из Whiteboard.vue).
     *
     * @param array<int, array<string, mixed>> $points уже очищенные точки
     */
    public function buildPath(array $points): string
    {
        $n = count($points);

        if ($n < 2) {
            return '';
        }

        $x  = static fn (array $p): float => (float) $p['x'];
        $y  = static fn (array $p): float => (float) $p['y'];
        $mx = static fn (array $a, array $b): float => ($x($a) + $x($b)) / 2;
        $my = static fn (array $a, array $b): float => ($y($a) + $y($b)) / 2;

        $d = 'M ' . $this->f($x($points[0])) . ' ' . $this->f($y($points[0]))
            . ' L ' . $this->f($mx($points[0], $points[1])) . ' ' . $this->f($my($points[0], $points[1]));

        for ($i = 1; $i <= $n - 2; $i++) {
            $d .= ' Q ' . $this->f($x($points[$i])) . ' ' . $this->f($y($points[$i]))
                . ' ' . $this->f($mx($points[$i], $points[$i + 1])) . ' ' . $this->f($my($points[$i], $points[$i + 1]));
        }

        return $d . ' L ' . $this->f($x($points[$n - 1])) . ' ' . $this->f($y($points[$n - 1]));
    }

    private function normalizeColor(mixed $color): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1
            ? $color
            : self::DEFAULT_COLOR;
    }

    private function rasterize(string $svg, int $width, int $height): string
    {
        $tmpDir = sys_get_temp_dir();
        $svgFile = $tmpDir . '/wb_' . bin2hex(random_bytes(8)) . '.svg';
        $pngFile = $tmpDir . '/wb_' . bin2hex(random_bytes(8)) . '.png';

        file_put_contents($svgFile, $svg);

        try {
            $process = new Process([
                'rsvg-convert',
                '-f', 'png',
                '-w', (string) $width,
                '-h', (string) $height,
                '-o', $pngFile,
                $svgFile,
            ]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $png = file_get_contents($pngFile);
        } finally {
            @unlink($svgFile);
            @unlink($pngFile);
        }

        if (!is_string($png) || $png === '') {
            throw new RuntimeException('rsvg-convert вернул пустой PNG');
        }

        return $png;
    }

    private function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }
}
