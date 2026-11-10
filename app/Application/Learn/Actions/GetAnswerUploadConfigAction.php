<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

/**
 * Контракт загрузки изображений-ответов для фронта.
 *
 * Единый источник правды — config('learn.answers.*'). Фронт использует
 * эти значения для предвалидации (accept, размер) до отправки.
 */
final readonly class GetAnswerUploadConfigAction
{
    /**
     * @return array{
     *     allowed_mimes: list<string>,
     *     accept: string,
     *     max_bytes: int,
     *     max_side: int|null,
     *     converted_to_webp: bool
     * }
     */
    public function __invoke(): array
    {
        $allowedMimes = array_values(array_map(
            'strval',
            (array) config('learn.answers.allowed_mimes', []),
        ));

        $transcode = (bool) config('learn.answers.transcode', true);
        $resize = (bool) config('learn.answers.resize', true);
        $convertToWebp = (bool) config('learn.answers.convert_to_webp', true);

        return [
            'allowed_mimes'    => $allowedMimes,
            'accept'           => implode(',', $allowedMimes),
            'max_bytes'        => (int) config('learn.answers.max_bytes', 10 * 1024 * 1024),
            'max_side'         => $transcode && $resize
                ? (int) config('learn.answers.max_side', 1024)
                : null,
            'converted_to_webp' => $transcode && $convertToWebp,
        ];
    }
}
