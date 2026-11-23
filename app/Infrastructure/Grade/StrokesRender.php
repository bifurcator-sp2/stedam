<?php

namespace App\Infrastructure\Grade;

use App\Models\Learn;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Рендер рукописных штрихов whiteboard в PNG.
 *
 * Пайплайн: strokes -> SVG (с обрезкой по чернилам и масштабом во viewBox)
 * -> PNG (rsvg-convert). Боевой метод — stream(): PNG отдаётся потоком и не
 * копируется в память PHP. render() — отладочный: сохраняет PNG в локальный
 * temp/stroke_render, чтобы периодически глазами проверять корректность рендера.
 *
 * Обрезка и увеличение выполняются ДО растеризации: в SVG выставляется
 * viewBox по границам чернил, а рендер идёт в увеличенный холст. Векторный
 * масштаб даёт чёткие линии без bicubic-артефактов повторной растеризации.
 */
class StrokesRender
{
    private const DISK          = 'local';
    private const DIRECTORY     = 'temp/stroke_render';
    private const BACKGROUND    = '#ffffff';
    private const DEFAULT_COLOR = '#000000';
    private const DEFAULT_SIZE  = 2.0;

    /**
     * Штрихи whiteboard рисуются толщиной 2 CSS-px внутри холста 854x130,
     * то есть занимают около 1/400 кадра. При рендере 1:1 нейросеть теряет
     * детали (числитель/знаменатель дроби сливаются), поэтому PNG обрезается
     * по чернилам, масштабируется и кладётся на белый холст с полями.
     *
     * TARGET_WIDTH — желаемая ширина области чернил для распознавания,
     * MAX_SCALE оберегает от раздувания крошечных чернил, MAX_OUTPUT размеры
     * итогового PNG, чтобы не упереться в лимиты модели и в память rsvg.
     */
    private const CROP_PADDING      = 6;
    private const MIN_SCALE         = 2.0;
    private const MAX_SCALE         = 8.0;
    private const TARGET_WIDTH      = 1600;
    private const OUTPUT_MARGIN     = 24;
    private const MAX_OUTPUT        = 2400;
    private const MIN_STROKE_SIZE   = 0.5;

    /**
     * ОТЛАДОЧНЫЙ метод: рендерит PNG и сохраняет в локальный temp, чтобы
     * периодически глазами контролировать корректность рендера.
     * В боевом пайплайне не используется — там stream().
     *
     * Поток из stream() сразу пишется на диск через writeStream,
     * без промежуточной строки со всем PNG.
     *
     * @param array<int, array<string, mixed>> $strokes массив штрихов (формат JSON whiteboard)
     * @param int                              $whiteboardWidth  ширина холста в CSS-пикселях
     * @param int                              $whiteboardHeight высота холста в CSS-пикселях
     *
     * @return string путь PNG относительно корня диска local
     */
    public function render(array $strokes, int $whiteboardWidth, int $whiteboardHeight): string
    {
        $stream = $this->stream($strokes, $whiteboardWidth, $whiteboardHeight);

        try {
            $path = self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.png';

            if (!Storage::disk(self::DISK)->writeStream($path, $stream)) {
                throw new RuntimeException("Не удалось сохранить PNG в $path");
            }

            return $path;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Рендерит штрихи в PNG и отдаёт поток изображения, не копируя PNG в строку.
     *
     * Основной боевой путь. Обрезка по чернилам и увеличение выполняются в SVG
     * (viewBox), а не по пикселям: изображение растеризуется сразу в конечный
     * размер, поэтому нет ни двойной растеризации, ни bicubic-артефактов.
     * rsvg-convert пишет в файл в /dev/shm (-o), мы открываем его и сразу
     * удаляем — данные читаются из дескриптора и освобождаются при fclose.
     *
     * @param array<int, array<string, mixed>> $strokes массив штрихов (формат JSON whiteboard)
     * @param int                              $whiteboardWidth  ширина холста в CSS-пикселях
     * @param int                              $whiteboardHeight высота холста в CSS-пикселях
     * @param bool                             $saveDebugImage   сохранить копию PNG в temp/stroke_render (отладка)
     *
     * @return resource поток с бинарным содержимым PNG (читаемый с начала)
     */
    public function stream(
        array $strokes,
        int $whiteboardWidth,
        int $whiteboardHeight,
        bool $saveDebugImage = false,
    ) {
        if ($whiteboardWidth <= 0 || $whiteboardHeight <= 0) {
            throw new RuntimeException("Некорректный размер холста: {$whiteboardWidth}x{$whiteboardHeight}");
        }

        $geometry = $this->canvasGeometry($strokes, $whiteboardWidth, $whiteboardHeight);

        $svg = $this->buildSvg(
            $strokes,
            $geometry['width'],
            $geometry['height'],
            $geometry['viewBoxX'],
            $geometry['viewBoxY'],
            $geometry['viewBoxW'],
            $geometry['viewBoxH'],
        );

        $stream = $this->rasterizeToStream($svg, $geometry['width'], $geometry['height']);

        // SVG-строка может быть крупной — не держим её до конца вызова.
        unset($svg);

        if ($saveDebugImage) {
            $this->saveDebugCopy($stream);
        }

        return $stream;
    }

    /**
     * Геометрия холста для рендера: размер PNG и viewBox в координатах SVG.
     *
     * Чернила обрезаются по границам штрихов и увеличиваются так, чтобы
     * область (по горизонтали) заняла около TARGET_WIDTH пикселей. Масштаб
     * применяется к самому холсту через viewBox — линии остаются векторами
     * до момента растеризации, поэтому текст выходит чётким без артефактов.
     *
     * Если чернил нет (пустой ответ или одни ластики), холст отдаётся 1:1:
     * модель должна увидеть, что решения нет.
     *
     * @param array<int, array<string, mixed>> $strokes
     *
     * @return array{width: int, height: int, viewBoxX: float, viewBoxY: float, viewBoxW: float, viewBoxH: float}
     */
    private function canvasGeometry(array $strokes, int $whiteboardWidth, int $whiteboardHeight): array
    {
        $bounds = $this->computeInkBounds($strokes, $whiteboardWidth, $whiteboardHeight);

        if ($bounds === null) {
            return [
                'width'    => $whiteboardWidth,
                'height'   => $whiteboardHeight,
                'viewBoxX' => 0.0,
                'viewBoxY' => 0.0,
                'viewBoxW' => (float) $whiteboardWidth,
                'viewBoxH' => (float) $whiteboardHeight,
            ];
        }

        [$minX, $minY, $maxX, $maxY] = $bounds;

        $cropW = max(1.0, $maxX - $minX);
        $cropH = max(1.0, $maxY - $minY);

        // Целевой масштаб по ширине чернил + пределы, чтобы не раздувать PNG:
        // MAX_SCALE для крошечных чернил и MAX_OUTPUT по каждому измерению
        // для огромных, иначе крупный черновик упрётся в память rsvg-convert.
        $maxScaleByW = (self::MAX_OUTPUT - 2 * self::OUTPUT_MARGIN) / $cropW;
        $maxScaleByH = (self::MAX_OUTPUT - 2 * self::OUTPUT_MARGIN) / $cropH;

        $scale = min(self::TARGET_WIDTH / $cropW, self::MAX_SCALE, $maxScaleByW, $maxScaleByH);

        // MIN_SCALE применяем последним: минимальное увеличение важнее
        // лимита MAX_OUTPUT — иначе мелкие чернила останутся нечитаемыми.
        $scale = max(self::MIN_SCALE, $scale);

        $outputW = (int) round($cropW * $scale + 2 * self::OUTPUT_MARGIN);
        $outputH = (int) round($cropH * $scale + 2 * self::OUTPUT_MARGIN);

        $innerW = $outputW - 2 * self::OUTPUT_MARGIN;
        $innerH = $outputH - 2 * self::OUTPUT_MARGIN;

        // Размер области viewBox выводим из внутренних размеров PNG, а не из
        // scale напрямую: так пропорции PNG и viewBox совпадают точно, без
        // белых полос при preserveAspectRatio="none".
        $offsetX = ($innerW / $cropW - 1) / 2;
        $offsetY = ($innerH / $cropH - 1) / 2;

        return [
            'width'    => $outputW,
            'height'   => $outputH,
            'viewBoxX' => $minX - $cropW * $offsetX,
            'viewBoxY' => $minY - $cropH * $offsetY,
            'viewBoxW' => $innerW,
            'viewBoxH' => $innerH,
        ];
    }

    /**
     * Границы видимых чернил в координатах SVG.
     *
     * Учитывает толщину каждого штриха (size/2) и CROP_PADDING.
     * Если штрихов нет или все они ластики — возвращает null.
     *
     * @param array<int, array<string, mixed>> $strokes
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function computeInkBounds(
        array $strokes,
        int $canvasWidth,
        int $canvasHeight,
    ): ?array {
        $minX = PHP_FLOAT_MAX;
        $minY = PHP_FLOAT_MAX;
        $maxX = -PHP_FLOAT_MAX;
        $maxY = -PHP_FLOAT_MAX;

        foreach ($strokes as $stroke) {
            if (!is_array($stroke) || !empty($stroke['eraser'])) {
                continue;
            }

            $points = $this->validPoints($stroke);
            if ($points === []) {
                continue;
            }

            $half = $this->strokeSize($stroke) / 2.0;

            foreach ($points as $point) {
                $x = (float) $point['x'];
                $y = (float) $point['y'];

                $minX = min($minX, $x - $half);
                $maxX = max($maxX, $x + $half);
                $minY = min($minY, $y - $half);
                $maxY = max($maxY, $y + $half);
            }
        }

        if ($minX > $maxX) {
            return null;
        }

        $minX = max(0.0, $minX - self::CROP_PADDING);
        $minY = max(0.0, $minY - self::CROP_PADDING);
        $maxX = min((float) $canvasWidth, $maxX + self::CROP_PADDING);
        $maxY = min((float) $canvasHeight, $maxY + self::CROP_PADDING);

        if ($maxX <= $minX || $maxY <= $minY) {
            return null;
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * Толщина штриха с защитой от некорректных значений из JSON.
     *
     * @param array<string, mixed> $stroke
     */
    private function strokeSize(array $stroke): float
    {
        $size = is_numeric($stroke['size'] ?? null) ? (float) $stroke['size'] : self::DEFAULT_SIZE;

        return max(self::MIN_STROKE_SIZE, $size);
    }

    /**
     * Отладочная копия отрендеренного PNG в локальном temp/stroke_render.
     * Позиция потока сохраняется: после копирования он снова в начале.
     *
     * @param resource $stream
     */
    private function saveDebugCopy($stream): void
    {
        $path = self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.png';

        Storage::disk(self::DISK)->writeStream($path, $stream);

        rewind($stream);
    }

    /**
     * Рендерит все вайтборды из answers прохождения.
     *
     * answers — фронтенд-структура блоков: [{version, strokes, width?, height?}, ...].
     * Блоки без strokes пропускаются. Размер холста берётся из блока,
     * иначе — из переданных $defaultWidth/$defaultHeight.
     *
     * @return list<string> пути PNG относительно корня диска local
     */
    public function renderLearn(Learn $learn, int $defaultWidth = 854, int $defaultHeight = 314): array
    {
        $paths = [];

        foreach ((array) ($learn->answers ?? []) as $block) {
            if (!is_array($block) || !is_array($block['strokes'] ?? null)) {
                continue;
            }

            $width  = is_numeric($block['width'] ?? null) ? (int) $block['width'] : $defaultWidth;
            $height = is_numeric($block['height'] ?? null) ? (int) $block['height'] : $defaultHeight;

            $paths[] = $this->render($block['strokes'], $width, $height);
        }

        return $paths;
    }

    /**
     * @param array<int, array<string, mixed>> $strokes
     */
    public function buildSvg(
        array $strokes,
        int $width,
        int $height,
        ?float $viewBoxX = null,
        ?float $viewBoxY = null,
        ?float $viewBoxW = null,
        ?float $viewBoxH = null,
    ): string {
        // Оптимизация: вместо конкатенации строки на каждом штрихе (O(n²)
        // переаллокаций на больших холстах) SVG строится по частям и
        // склеивается один раз через implode.
        $parts = [
            $this->svgHeader(
                $width,
                $height,
                $viewBoxX ?? 0.0,
                $viewBoxY ?? 0.0,
                $viewBoxW ?? (float) $width,
                $viewBoxH ?? (float) $height,
            ),
        ];

        foreach ($strokes as $stroke) {
            $element = $this->buildStrokeElement($stroke);
            if ($element !== null) {
                $parts[] = $element;
            }
        }

        $parts[] = '</g></svg>';

        return implode('', $parts);
    }

    /**
     * Готовит точки штриха: отбрасывает битые, возвращает только валидные.
     *
     * @param array<string, mixed> $stroke
     * @return list<array<string, mixed>>
     */
    private function validPoints(array $stroke): array
    {
        $points = is_array($stroke['points'] ?? null) ? $stroke['points'] : [];

        $valid = [];
        foreach ($points as $point) {
            if (is_array($point) && is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null)) {
                $valid[] = $point;
            }
        }

        return $valid;
    }

    /**
     * Один штрих whiteboard → SVG-элемент. null — штрих пропускается
     * (не массив, ластик, нет валидных точек).
     *
     * @param mixed $stroke
     */
    private function buildStrokeElement($stroke): ?string
    {
        if (!is_array($stroke)) {
            return null;
        }

        // TODO: eraser via SVG <mask> (destination-out) — пока штрихи-ластики пропускаются.
        if (!empty($stroke['eraser'])) {
            return null;
        }

        $points = $this->validPoints($stroke);
        if ($points === []) {
            return null;
        }

        $size  = $this->strokeSize($stroke);
        $color = $this->normalizeColor($stroke['color'] ?? null);

        if (count($points) === 1) {
            return '<circle cx="' . $this->f((float) $points[0]['x'])
                . '" cy="' . $this->f((float) $points[0]['y'])
                . '" r="' . $this->f($size / 2)
                . '" fill="' . $color . '" stroke="none"/>';
        }

        return '<path d="' . $this->buildPath($points)
            . '" stroke="' . $color
            . '" stroke-width="' . $this->f($size) . '"/>';
    }

    /**
     * Открывающая часть SVG — до первого штриха.
     *
     * Фон рисуется по viewBox, а не по width/height: при обрезке по чернилам
     * белый прямоугольник должен покрывать именно видимую область.
     * preserveAspectRatio="none" — размеры viewBox и PNG в canvasGeometry()
     * согласованы, поэтому растягивать нечего, а meet/mice дали бы полосы
     * из-за округления размеров.
     */
    private function svgHeader(
        int $width,
        int $height,
        float $viewBoxX,
        float $viewBoxY,
        float $viewBoxW,
        float $viewBoxH,
    ): string {
        $vbX = $this->f($viewBoxX);
        $vbY = $this->f($viewBoxY);
        $vbW = $this->f($viewBoxW);
        $vbH = $this->f($viewBoxH);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '"'
            . ' viewBox="' . $vbX . ' ' . $vbY . ' ' . $vbW . ' ' . $vbH . '"'
            . ' preserveAspectRatio="none" version="1.1">'
            . '<rect x="' . $vbX . '" y="' . $vbY . '" width="' . $vbW . '" height="' . $vbH
            . '" fill="' . self::BACKGROUND . '"/>'
            . '<g fill="none" stroke-linecap="round" stroke-linejoin="round">';
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

    /**
     * Растеризует SVG в PNG, не материализуя результат в строку PHP.
     *
     * Почему не stdout процесса в поток PHP: Symfony Process sendOutput(),
     * отдающий stdout в наш поток, молча пишет 0 байт (проверено на этой
     * версии Symfony) — процесс завершается успешно, но PNG теряется.
     * Поэтому rsvg-convert пишет в файл (-o), файл лежит в /dev/shm (tmpfs,
     * это RAM-ФС), мы открываем его на чтение и сразу удаляем из каталога:
     * inode живёт, пока открыт дескриптор, а после fclose автоматически
     * освобождается. Так PNG не появляется в памяти PHP и не остаётся на диске.
     *
     * @return resource поток с PNG, позиция — в начале
     */
    private function rasterizeToStream(string $svg, int $width, int $height)
    {
        $tmpDir  = is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $tmpPath = rtrim($tmpDir, '/') . '/stroke_' . bin2hex(random_bytes(16)) . '.png';

        $process = new Process([
            'rsvg-convert',
            '-f', 'png',
            '-w', (string) $width,
            '-h', (string) $height,
            '-o', $tmpPath,
        ]);
        $process->setInput($svg);
        $process->run();

        // При ошибке важно отдать stderr, иначе диагностика сводится к
        // «пустой PNG» и не видно, почему rsvg-convert не сработал.
        if (!$process->isSuccessful()) {
            @unlink($tmpPath);

            throw new ProcessFailedException($process);
        }

        // @unlink сразу после fopen: если файл пустой или открыть не вышло,
        // временный файл всё равно убираем, а данные дочитываем из дескриптора.
        $stream = @fopen($tmpPath, 'rb');
        @unlink($tmpPath);

        if ($stream === false) {
            throw new RuntimeException('rsvg-convert не создал PNG-файл');
        }

        $stat = fstat($stream);
        if ($stat === false || $stat['size'] === 0) {
            fclose($stream);

            throw new RuntimeException(
                'rsvg-convert вернул пустой PNG. stderr: ' . trim($process->getErrorOutput())
            );
        }

        return $stream;
    }

    private function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }
}
