<?php

namespace Tests\Feature\Infrastructure\Grade;

use App\Infrastructure\Grade\StrokesRender;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StrokesRenderSmokeTest extends TestCase
{
    public function test_renders_png_into_temp_strokes_directory(): void
    {
        Storage::fake('local');

        $strokes = [
            [
                'size' => 2, 'color' => '#000000', 'eraser' => false,
                'points' => [
                    ['x' => 159.5, 'y' => 37.0, 'pressure' => 0.5],
                    ['x' => 160.28, 'y' => 36.23, 'pressure' => 0.5],
                    ['x' => 161.0, 'y' => 36.0],
                    ['x' => 162.0, 'y' => 35.0],
                ],
            ],
            [
                'size' => 2, 'color' => '#000000', 'eraser' => false,
                'points' => [
                    ['x' => 293.5, 'y' => 56],
                    ['x' => 300, 'y' => 40],
                    ['x' => 350, 'y' => 30],
                    ['x' => 400, 'y' => 45],
                    ['x' => 443.22, 'y' => 50],
                ],
            ],
            [
                'size' => 2, 'color' => '#000000', 'eraser' => false,
                'points' => [
                    ['x' => 539.5, 'y' => 37],
                    ['x' => 560, 'y' => 34],
                    ['x' => 600, 'y' => 36],
                    ['x' => 620.21, 'y' => 43],
                ],
            ],
            // ластик — пропускается
            ['size' => 20, 'color' => '#000000', 'eraser' => true, 'points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]]],
            // одна точка
            ['size' => 4, 'color' => '#000000', 'eraser' => false, 'points' => [['x' => 200, 'y' => 100]]],
            // битые точки
            ['size' => 2, 'eraser' => false, 'points' => [['x' => 'bad', 'y' => 1], ['x' => 5, 'y' => null]]],
        ];

        $render = app(StrokesRender::class);
        $path   = $render->render($strokes, 854, 314);

        $this->assertMatchesRegularExpression('#^temp/stroke_render/[0-9a-f]{32}\.png$#', $path);
        Storage::disk('local')->assertExists($path);

        $png = Storage::disk('local')->get($path);
        $this->assertIsString($png);
        $this->assertStringStartsWith("\x89PNG", $png);

        // PNG подготовлен для нейросети: обрезан по чернилам и увеличен,
        // поэтому размер кадра не совпадает с холстом 1:1.
        $header = unpack('Nwidth/Nheight', substr($png, 16, 8));
        $this->assertGreaterThan(854, $header['width']);

        $svg = $render->buildSvg($strokes, 854, 314);
        $this->assertSame(3, substr_count($svg, '<path'));
        $this->assertSame(1, substr_count($svg, '<circle'));
        $this->assertStringContainsString('fill="#ffffff"', $svg);

        $emptyPath = $render->render([], 10, 10);
        $this->assertStringStartsWith(
            'temp/stroke_render/',
            $emptyPath,
        );

        // Пустой ответ не масштабируется: нейросеть должна видеть пустой лист.
        $emptyHeader = unpack('Nwidth/Nheight', substr(Storage::disk('local')->get($emptyPath), 16, 8));
        $this->assertSame(10, $emptyHeader['width']);
        $this->assertSame(10, $emptyHeader['height']);
    }

    public function test_builds_smooth_path_through_midpoints(): void
    {
        $path = app(StrokesRender::class)->buildPath([
            ['x' => 159.5, 'y' => 37.0],
            ['x' => 160.28, 'y' => 36.23],
            ['x' => 161.0, 'y' => 36.0],
        ]);

        $this->assertSame('M 159.5 37 L 159.89 36.61 Q 160.28 36.23 160.64 36.11 L 161 36', $path);
    }
}
