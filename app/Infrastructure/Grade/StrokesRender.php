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
 * Пайплайн: strokes -> SVG -> PNG (rsvg-convert). Боевой метод — stream():
 * PNG отдаётся потоком и не копируется в память PHP. render() — отладочный:
 * сохраняет PNG в локальный temp/stroke_render, чтобы периодически глазами
 * проверять корректность рендера.
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
     */
    private const CROP_PADDING      = 6;
    private const MIN_SCALE         = 2;
    private const MAX_SCALE         = 8;
    private const TARGET_WIDTH      = 1600;
    private const OUTPUT_MARGIN     = 24;
    private const INK_LUMINANCE     = 160;

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
                throw new RuntimeException("Не удалось сохранить PNG в {$path}");
            }

            return $path;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Рендерит штрихи в PNG и отдаёт поток изображения, не копируя PNG в строку.
     *
     * Основной боевой путь. PNG ни разу не материализуется в строку PHP:
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

        $svg = $this->buildSvg($strokes, $whiteboardWidth, $whiteboardHeight);

        $stream = $this->rasterizeToStream($svg, $whiteboardWidth, $whiteboardHeight);

        // SVG-строка может быть крупной — не держим её до конца вызова.
        unset($svg);

        // Тихие тонкие линии нейросеть не разбирает: вырезаем область чернил
        // и увеличиваем её. Пустой холст остаётся пустым — модель должна
        // увидеть, что решения нет.
        $stream = $this->prepareForGrading($stream);

        if ($saveDebugImage) {
            $this->saveDebugCopy($stream);
        }

        return $stream;
    }

    /**
     * Обрезает PNG по чернилам и увеличивает до читаемого размера.
     *
     * Без этой подготовки штрих толщиной 2 px на холсте 854x130 занимает
     * 1/400 кадра и при распознавании сливается: модель видит «кашу».
     * Если чернил нет (пустой ответ), холст возвращается без изменений.
     *
     * @param resource $stream поток с PNG (читаемый, изначально в начале)
     *
     * @return resource поток с подготовленным PNG
     */
    private function prepareForGrading($stream)
    {
        $png = stream_get_contents($stream);
        fclose($stream);

        if ($png === false || $png === '') {
            throw new RuntimeException('Не удалось прочитать PNG для подготовки');
        }

        $image = @imagecreatefromstring($png);
        unset($png);

        if ($image === false) {
            throw new RuntimeException('GD не смог разобрать PNG');
        }

        try {
            $prepared = $this->cropInk($image);
        } finally {
            imagedestroy($image);
        }

        ob_start();
        imagepng($prepared);
        $out = (string) ob_get_clean();
        imagedestroy($prepared);

        $result = fopen('php://temp', 'r+b');
        if ($result === false) {
            throw new RuntimeException('Не удалось создать поток для PNG');
        }

        fwrite($result, $out);
        rewind($result);

        return $result;
    }

    /**
     * Вырезает прямоугольник с чернилами, масштабирует его и кладёт на белый
     * холст с полями. Если чернил нет, возвращает исходное изображение.
     *
     * @param \GdImage $image
     */
    private function cropInk($image)
    {
        $width  = imagesx($image);
        $height = imagesy($image);

        [$minX, $minY, $maxX, $maxY] = $this->inkBounds($image, $width, $height);

        if ($maxX < $minX) {
            return $image;
        }

        $minX = max(0, $minX - self::CROP_PADDING);
        $minY = max(0, $minY - self::CROP_PADDING);
        $maxX = min($width - 1, $maxX + self::CROP_PADDING);
        $maxY = min($height - 1, $maxY + self::CROP_PADDING);

        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;

        $scale = (int) max(self::MIN_SCALE, min(self::MAX_SCALE, floor(self::TARGET_WIDTH / max(1, $cropW))));

        $crop = imagecrop($image, ['x' => $minX, 'y' => $minY, 'width' => $cropW, 'height' => $cropH]);
        if ($crop === false) {
            return $image;
        }

        $scaled = imagescale($crop, $cropW * $scale, $cropH * $scale, IMG_BICUBIC_FIXED);
        imagedestroy($crop);

        if ($scaled === false) {
            return $image;
        }

        $canvasW = $cropW * $scale + 2 * self::OUTPUT_MARGIN;
        $canvasH = $cropH * $scale + 2 * self::OUTPUT_MARGIN;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        $white  = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $canvasW - 1, $canvasH - 1, $white);
        imagecopy($canvas, $scaled, self::OUTPUT_MARGIN, self::OUTPUT_MARGIN, 0, 0, $cropW * $scale, $cropH * $scale);

        imagedestroy($scaled);

        return $canvas;
    }

    /**
     * Границы тёмных пикселей. Если чернил нет — [$width, $height, -1, -1].
     *
     * @param \GdImage $image
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function inkBounds($image, int $width, int $height): array
    {
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $luminance = (($rgb >> 16) & 0xFF) * 0.299
                    + (($rgb >> 8) & 0xFF) * 0.587
                    + ($rgb & 0xFF) * 0.114;

                if ($luminance < self::INK_LUMINANCE) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return [$minX, $minY, $maxX, $maxY];
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
    public function buildSvg(array $strokes, int $width, int $height): string
    {
        // Оптимизация: вместо конкатенации строки на каждом штрихе (O(n²)
        // переаллокаций на больших холстах) SVG строится по частям и
        // склеивается один раз через implode.
        $parts = [$this->svgHeader($width, $height)];

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

        $size  = is_numeric($stroke['size'] ?? null) ? (float) $stroke['size'] : self::DEFAULT_SIZE;
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
     */
    private function svgHeader(int $width, int $height): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '"'
            . ' viewBox="0 0 ' . $width . ' ' . $height . '" version="1.1">'
            . '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="' . self::BACKGROUND . '"/>'
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
