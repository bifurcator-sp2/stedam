<?php

declare(strict_types=1);

use App\Application\Learn\DTOs\RawAnswer;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Infrastructure\Learn\S3AnswerStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\ImageManagerInterface;

function answerStorage(
    int $maxSide = 1024,
    int $quality = 80,
    bool $transcode = true,
    bool $resize = true,
    bool $convertToWebp = true,
): S3AnswerStorage {
    Storage::fake('s3_public');

    return new S3AnswerStorage(
        filesystems: Storage::getFacadeRoot(),
        images: app(ImageManagerInterface::class),
        disk: 's3_public',
        prefix: 'answers',
        transcode: $transcode,
        resize: $resize,
        maxSide: $maxSide,
        convertToWebp: $convertToWebp,
        quality: $quality,
    );
}

function rawImageAnswer(int $index, int $w = 400, int $h = 300): RawAnswer
{
    return new RawAnswer(
        $index,
        UploadedFile::fake()->image("a{$index}.png", $w, $h),
    );
}

it('stores webp images under sharded answers path', function () {
    $storage = answerStorage();
    $learnId = new LearnId(1234);

    $images = $storage->replaceAll($learnId, [
        rawImageAnswer(2),
        rawImageAnswer(1),
    ]);

    // Shard = 1234 % 1000 = 234 → '234'.
    expect($images[0]->file)->toBe('answers/234/1234/2.webp')
        ->and($images[1]->file)->toBe('answers/234/1234/1.webp');

    Storage::disk('s3_public')->assertExists('answers/234/1234/1.webp');
    Storage::disk('s3_public')->assertExists('answers/234/1234/2.webp');

    // Содержимое — валидный webp.
    $bytes = Storage::disk('s3_public')->get('answers/234/1234/1.webp');
    expect(substr($bytes, 8, 4))->toBe('WEBP');
});

it('scales down by max side (no upscale)', function () {
    $storage = answerStorage(maxSide: 100);

    $storage->replaceAll(new LearnId(1), [rawImageAnswer(1, w: 500, h: 250)]);

    $bytes = Storage::disk('s3_public')->get('answers/001/1/1.webp');
    $info = getimagesizefromstring($bytes);

    expect($info[0])->toBe(100)   // width scaled to 100
        ->and($info[1])->toBe(50); // height keeps aspect
});

it('replaces previous files on resave (same index overwrites, stale removed)', function () {
    $storage = answerStorage();
    $learnId = new LearnId(77);

    $storage->replaceAll($learnId, [rawImageAnswer(1), rawImageAnswer(2)]);
    Storage::disk('s3_public')->assertExists('answers/077/77/2.webp');

    // Второе сохранение — только index 1; файл index 2 должен исчезнуть.
    $storage->replaceAll($learnId, [rawImageAnswer(1)]);

    Storage::disk('s3_public')->assertExists('answers/077/77/1.webp');
    Storage::disk('s3_public')->assertMissing('answers/077/77/2.webp');
});

it('builds public url for an answer', function () {
    $storage = answerStorage();
    $images = $storage->replaceAll(new LearnId(5), [rawImageAnswer(1)]);

    $url = $storage->urlFor($images[0]);

    expect($url)->toContain('answers/005/5/1.webp');
});

it('pass-through when transcode disabled (keeps original bytes and ext)', function () {
    $storage = answerStorage(transcode: false);

    $file = UploadedFile::fake()->image('orig.png', 800, 600);
    $originalBytes = file_get_contents($file->getRealPath());

    $images = $storage->replaceAll(new LearnId(9), [new RawAnswer(1, $file)]);

    expect($images[0]->file)->toBe('answers/009/9/1.png');
    $stored = Storage::disk('s3_public')->get('answers/009/9/1.png');
    expect($stored)->toBe($originalBytes); // байт-в-байт, без ресайза
});

it('skips resize but still converts to webp when resize disabled', function () {
    $storage = answerStorage(resize: false, convertToWebp: true);

    $storage->replaceAll(new LearnId(9), [rawImageAnswer(1, w: 2000, h: 1000)]);

    $bytes = Storage::disk('s3_public')->get('answers/009/9/1.webp');
    $info = getimagesizefromstring($bytes);

    expect(substr($bytes, 8, 4))->toBe('WEBP')
        ->and($info[0])->toBe(2000); // размер исходный, ресайза нет
});

it('resizes but keeps original format when webp disabled', function () {
    $storage = answerStorage(maxSide: 100, convertToWebp: false);

    $file = UploadedFile::fake()->image('photo.jpg', 500, 250);
    $images = $storage->replaceAll(new LearnId(9), [new RawAnswer(1, $file)]);

    expect($images[0]->file)->toBe('answers/009/9/1.jpg');

    $bytes = Storage::disk('s3_public')->get('answers/009/9/1.jpg');
    $info = getimagesizefromstring($bytes);
    expect($info[0])->toBe(100)
        ->and($info[1])->toBe(50);
});
