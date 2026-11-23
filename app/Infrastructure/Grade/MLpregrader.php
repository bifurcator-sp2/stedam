<?php

namespace App\Infrastructure\Grade;

use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Enums\LearnGradeStatus;
use App\Infrastructure\Blocks\TestsResolver;
use App\Models\LearnGrade;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class MLpregrader
{
    public function __construct(
        private StrokesRender $render,
        private LearnRepositoryInterface $learns,
        private SnapshotReaderInterface $snapshots,
    ) {
    }

    /**
     * Предварительная обработка одной оценки: рендер вайтбордов из answers
     * и перевод статуса pre_grading → pre_graded / failed.
     *
     * @throws Throwable пробрасывается после записи статуса failed
     */
    public function pregrade(LearnGrade $grade): void
    {
        try {

            $testsWithAnswersForMLgrading = (new TestsResolver(
                $this->render,
                $this->learns,
                $this->snapshots,
            ))->resolveTestsWithAnswers($grade);

            // Класс объявлен с namespace App\Services, но файл лежит рядом
            // с этим классом, поэтому PSR-4 его не находит — подключаем по пути.
            require_once __DIR__ . '/DeepSeekAnswerGrader.php';

            $grader = new DeepSeekAnswerGrader();
            $results = [];

            foreach ($testsWithAnswersForMLgrading as $test) {
                $studentAnswer = $test['student_answer'] ?? null;

                // Если ответ пришёл картинкой (data URI) — отдаём её напрямую,
                // без рендера штрихов.
                if (is_string($studentAnswer) && str_starts_with($studentAnswer, 'data:')) {
                    $studentPng = null;
                    $dataUri = $studentAnswer;
                } else {
                    $strokeBlocks = $studentAnswer['strokeBlocks'] ?? [];
                    $width  = (int) ($studentAnswer['width'] ?? 854);
                    $height = (int) ($studentAnswer['height'] ?? 314);

                    // render->stream в цикле: каждый PNG живёт только до конца
                    // итерации, память не копится на больших уроках.
                    $studentPng = $this->render->stream($strokeBlocks, $width, $height);
                    $dataUri = null;
                }

                try {
                    $verdict = $grader->grade(
                        questionHtml:  (string) ($test['task'] ?? ''),
                        referenceHtml: (string) ($test['solution'] ?? ''),
                        studentPng:    $studentPng ?? $this->streamFromDataUri($dataUri),
                    );
                    $results[] = [
                        'order'      => $test['order'] ?? null,
                        'complexity' => $test['complexity'] ?? null,
                        'score'      => $verdict['score'],
                        'confidence' => $verdict['confidence'],
                        'comment'    => $verdict['comment'],
                        'tokens'     => $verdict['tokens'],
                    ];
                } finally {
                    if (is_resource($studentPng)) {
                        fclose($studentPng);
                    }
                }
            }

            Log::info('ML pregrade results', [
                'grade_id' => $grade->id,
                'results'  => $results,
            ]);

            LearnGrade::query()->whereKey($grade->id)->update([
                'status' => LearnGradeStatus::PreGraded->value,
                'error'  => null,
            ]);
        } catch (Throwable $e) {
            LearnGrade::query()->whereKey($grade->id)->update([
                'status' => LearnGradeStatus::Failed->value,
                'error'  => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Оборачивает готовый data URI картинки в поток, чтобы отдать его в grader.
     *
     * @return resource
     */
    private function streamFromDataUri(string $dataUri)
    {
        $comma = strpos($dataUri, ',');
        $binary = base64_decode($comma === false ? $dataUri : substr($dataUri, $comma + 1), true);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Не удалось создать поток для PNG');
        }

        fwrite($stream, $binary === false ? '' : $binary);
        rewind($stream);

        return $stream;
    }
}
