<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\DTOs\RawAnswer;
use App\Application\Learn\Ports\AnswerStorageInterface;
use App\Domain\Learn\ValueObjects\AnswerImage;
use App\Domain\Learn\ValueObjects\LearnId;
use Illuminate\Contracts\Filesystem\Factory as FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Intervention\Image\Interfaces\ImageManagerInterface;

/**
 * Хранит изображения-ответы в публичном S3.
 *
 * Раскладка (шардированная, корень — config('learn.answers.prefix')):
 *   {prefix}/{learnId % 1000:03}/{learnId}/{index}.{ext}
 *
 * Обработка управляется флагами (см. config/learn.php → answers):
 *   - transcode=false        → файл сохраняется как есть (pass-through);
 *   - resize=true            → масштаб по большой стороне до max_side;
 *   - convert_to_webp=true   → перекодирование в webp с quality.
 *
 * replaceAll — полная замена: удаляет все старые файлы папки прохождения
 * перед записью новых (последнее автосохранение побеждает).
 */
final readonly class S3AnswerStorage implements AnswerStorageInterface
{
    private const WEBP = 'webp';

    public function __construct(
        private FilesystemManager $filesystems,
        private ImageManagerInterface $images,
        private string $disk,
        private string $prefix,
        private bool $transcode,
        private bool $resize,
        private int $maxSide,
        private bool $convertToWebp,
        private int $quality,
    ) {}

    public function replaceAll(LearnId $learnId, array $answers): array
    {
        $dir = $this->dirFor($learnId);
        $disk = $this->disk();

        // Полная замена — чистим старые файлы этого прохождения.
        $disk->deleteDirectory($dir);

        $result = [];
        foreach ($answers as $raw) {
            /** @var RawAnswer $raw */
            [$binary, $ext] = $this->process($raw);
            $path = $dir.'/'.$raw->index.'.'.$ext;

            $disk->put($path, $binary);

            $result[] = new AnswerImage($raw->index, $path);
        }

        return $result;
    }

    /**
     * Обрабатывает загруженное изображение согласно флагам.
     *
     * @return array{0: string, 1: string} [бинарные данные, расширение без точки]
     */
    private function process(RawAnswer $raw): array
    {
        $sourcePath = $raw->image->getRealPath();

        // Pass-through: ничего не декодируем, берём как есть.
        if (!$this->transcode) {
            return [(string) file_get_contents($sourcePath), $this->sourceExtension($raw)];
        }

        $image = $this->images->decodePath($sourcePath);

        if ($this->resize && $this->maxSide > 0) {
            $image = $image->scaleDown(width: $this->maxSide, height: $this->maxSide);
        }

        if ($this->convertToWebp) {
            return [
                $image->encodeUsingFileExtension(self::WEBP, quality: $this->quality)->toString(),
                self::WEBP,
            ];
        }

        // Без webp — кодируем в исходном формате (или png, если не распознан).
        $ext = $this->sourceExtension($raw);
        if ($ext === 'heic') {
            $ext = 'png'; // Intervention/GD не умеет кодировать heic
        }

        return [
            $image->encodeUsingFileExtension($ext, quality: $this->quality)->toString(),
            $ext,
        ];
    }

    /**
     * Расширение исходного изображения (нормализованное, безопасное для пути).
     */
    private function sourceExtension(RawAnswer $raw): string
    {
        $ext = strtolower($raw->image->getClientOriginalExtension());

        return match ($ext) {
            'jpeg', 'jpg' => 'jpg',
            'png', 'webp', 'gif' => $ext,
            'heic' => 'heic',
            'bmp' => 'bmp',
            default => 'png',
        };
    }

    public function urlFor(AnswerImage $image): string
    {
        return $this->disk()->url($image->file);
    }

    private function dirFor(LearnId $learnId): string
    {
        $shard = str_pad((string) ($learnId->value % 1000), 3, '0', STR_PAD_LEFT);

        return sprintf('%s/%s/%d', trim($this->prefix, '/'), $shard, $learnId->value);
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk($this->disk);
    }
}
