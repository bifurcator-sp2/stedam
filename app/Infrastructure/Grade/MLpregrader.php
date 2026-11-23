<?php

namespace App\Infrastructure\Grade;

use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Enums\LearnGradeStatus;
use App\Infrastructure\Blocks\TestsResolver;
use App\Models\LearnGrade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * Фиксация результата делается только если строку всё ещё держит этот
     * воркер (status = pre_grading + pre_grade_worker = $workerId): так
     * запоздавший обработчик не перезапишет чужой результат после recovery.
     *
     * На время обработки аренда продлевается (heartbeat) после каждого
     * задания — длинные learn не теряют строку из-за истечения lease.
     *
     * @throws Throwable пробрасывается после записи статуса failed
     */
    public function pregrade(LearnGrade $grade, ?string $workerId = null, ?int $leaseSeconds = null): void
    {
        try {
            // Сердцебиение аренды: возобновляется только владельцем строки.
            $heartbeat = function () use ($grade, $workerId, $leaseSeconds): bool {
                if ($workerId === null || $leaseSeconds === null) {
                    return true;
                }

                return LearnGrade::query()
                    ->whereKey($grade->id)
                    ->where('status', LearnGradeStatus::PreGrading->value)
                    ->where('pre_grade_worker', $workerId)
                    ->update([
                        'pre_grade_lease_until' => now()->addSeconds($leaseSeconds),
                    ]) > 0;
            };

            // Честный пик памяти именно для этого learn: сбрасываем
            // глобальный пик процесса (воркер долгоживущий) и мусор от
            // предыдущей итерации.
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memoryBase = memory_get_usage(false);

            $testsWithAnswersForMLgrading = (new TestsResolver(
                $this->render,
                $this->learns,
                $this->snapshots,
            ))->resolveTestsWithAnswers($grade);

            // Рендер/чтение ответов тоже может быть долгим — продлеваем lease.
            $heartbeat();

            $grader = new DeepSeekAnswerGrader();
            $results = [];

            $startedAt = microtime(true);
            $totalTests = count($testsWithAnswersForMLgrading);
            $checkedTests = 0;
            $totalTokens = 0;

            foreach ($testsWithAnswersForMLgrading as $test) {
                $studentAnswer = $test['student_answer'] ?? null;

                // Если ответ пришёл картинкой (data URI) — отдаём её напрямую,
                // без рендера штрихов.
                if (is_array($studentAnswer) && is_string($studentAnswer['data_uri'] ?? null)) {
                    $studentPng = null;
                    $dataUri = $studentAnswer['data_uri'];
                } else {
                    // Ответ отсутствует или пришёл штрихами. Пустой ответ
                    // рендерим в пустой холст: грейдер должен оценить, что
                    // решения нет, а в дебаге будет видно пустое изображение.
                    $strokeBlocks = is_array($studentAnswer) && is_array($studentAnswer['strokeBlocks'] ?? null)
                        ? $studentAnswer['strokeBlocks']
                        : [];
                    $width  = (int) ($studentAnswer['width'] ?? 854);
                    $height = (int) ($studentAnswer['height'] ?? 314);

                    // render->stream в цикле: каждый PNG живёт только до конца
                    // итерации, память не копится на больших уроках.
                    // saveDebugImage: копия сохраняется всегда — включая пустые
                    // ответы без штрихов, чтобы на диске было видно пустой холст.
                    $studentPng = $this->render->stream(
                        $strokeBlocks,
                        $width,
                        $height,
                        saveDebugImage: true,
                    );
                    $dataUri = null;
                }

                try {
                    $verdict = $grader->grade(
                        questionHtml:  (string) ($test['task'] ?? ''),
                        referenceHtml: (string) ($test['solution'] ?? ''),
                        studentPng:    $studentPng ?? $this->streamFromDataUri($dataUri, saveDebugImage: true),
                    );
                    $checkedTests++;
                    $totalTokens += (int) ($verdict['tokens'] ?? 0);
                    $results[] = [
                        'order'      => $test['order'] ?? null,
                        'complexity' => $test['complexity'] ?? null,
                        'score'      => $verdict['score'],
                        'confidence' => $verdict['confidence'],
                        'comment'    => $verdict['comments'] ?? '',
                        'tokens'     => $verdict['tokens'],
                    ];
                } catch (Throwable $e) {
                    /*Log::warning('ML pregrade test failed', [
                        'grade_id' => $grade->id,
                        'order'    => $test['order'] ?? null,
                        'error'    => $e->getMessage(),
                    ]);*/

                    // Задание не прошло проверку — пишем ошибку в results,
                    // оно не учитывается в средних значениях.
                    $results[] = [
                        'order'      => $test['order'] ?? null,
                        'complexity' => $test['complexity'] ?? null,
                        'score'      => null,
                        'confidence' => null,
                        'comment'    => '',
                        'error'      => $e->getMessage(),
                    ];
                } finally {
                    if (is_resource($studentPng)) {
                        fclose($studentPng);
                    }
                }

                // Аренда потеряна (recovery вернул строку в pending или её
                // забрал другой воркер) — дальше считать нет смысла, финал
                // всё равно не применится.
                if (!$heartbeat()) {
                    Log::warning('ML pregrade: lease lost during processing, aborting', [
                        'grade_id' => $grade->id,
                        'worker'   => $workerId,
                        'order'    => $test['order'] ?? null,
                    ]);

                    return;
                }
            }

            $elapsedSeconds = microtime(true) - $startedAt;

            // После сброса пика (выше) это максимум именно для текущего learn.
            // Берём прирост над базой (memory_get_usage(false) — только занятые
            // данными страницы) и переводим в МБ.
            $peakMemoryMb = max(
                0.0,
                round((memory_get_peak_usage(false) - $memoryBase) / 1048576, 3),
            );

            // Средние считаем только по заданиям, прошедшим проверку без ошибок.
            $scores = [];
            $confidences = [];
            foreach ($results as $result) {
                if ($result['score'] !== null && $result['confidence'] !== null) {
                    $scores[] = $result['score'];
                    $confidences[] = $result['confidence'];
                }
            }

            $coverage = $totalTests > 0 ? $checkedTests / $totalTests : 0.0;
            $avgScore = $scores !== [] ? array_sum($scores) / count($scores) : null;
            $avgConfidence = $confidences !== [] ? array_sum($confidences) / count($confidences) : null;

            /*Log::info('ML pregrade results', [
                'grade_id' => $grade->id,
                'results'  => $results,
            ]);*/

            $preGrade = $this->clamp01($avgScore);
            $preGradeConfidence = $this->clamp01($avgConfidence);

            $updated = LearnGrade::query()
                ->whereKey($grade->id)
                ->where('status', LearnGradeStatus::PreGrading->value)
                ->when(
                    $workerId !== null,
                    fn ($query) => $query->where('pre_grade_worker', $workerId),
                )
                // Не применяем результат по истёкшей аренде: строку уже мог
                // вернуть recovery, а обработчик — успеть зафиксировать финал.
                ->where(function ($query): void {
                    $query->whereNull('pre_grade_lease_until')
                        ->orWhere('pre_grade_lease_until', '>', now());
                })
                ->update([
                    'status'                => LearnGradeStatus::PreGraded->value,
                    'error'                 => null,
                    'pre_grade'             => $preGrade,
                    'pre_grade_confidence'  => $preGradeConfidence,
                    'pre_grade_coverage'    => $coverage,
                    'pre_grade_info'        => json_encode($results, JSON_UNESCAPED_UNICODE),
                    'pre_grade_cost'        => (float) $totalTokens,
                    'pre_grade_time'        => $elapsedSeconds,
                    'pre_grade_memory'      => $peakMemoryMb,
                    'pre_grade_worker'      => null,
                    'pre_grade_lease_until' => null,
                ]);

            if ($updated === 0) {
                // Аренда потеряна (recovery вернул строку в pending или её
                // забрал другой воркер) — результат не применяем.
                Log::warning('ML pregrade: lease lost, result discarded', [
                    'grade_id' => $grade->id,
                    'worker'   => $workerId,
                ]);
            }
        } catch (Throwable $e) {
            $updated = LearnGrade::query()
                ->whereKey($grade->id)
                ->where('status', LearnGradeStatus::PreGrading->value)
                ->when(
                    $workerId !== null,
                    fn ($query) => $query->where('pre_grade_worker', $workerId),
                )
                ->update([
                    'status'                => LearnGradeStatus::Failed->value,
                    'error'                 => $e->getMessage(),
                    'pre_grade_worker'      => null,
                    'pre_grade_lease_until' => null,
                ]);

            if ($updated === 0) {
                Log::warning('ML pregrade: lease lost on failure, status untouched', [
                    'grade_id' => $grade->id,
                    'worker'   => $workerId,
                    'error'    => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Приводит значение к допустимому для pre_grade диапазону (0..1],
     * т.к. на колонке стоит CHECK (значение > 0 И <= 1). NULL сохраняется как NULL.
     */
    private function clamp01(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return max(0.01, min(1.0, round($value, 4)));
    }

    /**
     * Оборачивает готовый data URI картинки в поток, чтобы отдать его в grader.
     *
     * @param bool $saveDebugImage сохранить копию картинки в temp/stroke_render (отладка)
     *
     * @return resource
     */
    private function streamFromDataUri(string $dataUri, bool $saveDebugImage = false)
    {
        $comma = strpos($dataUri, ',');
        $binary = base64_decode($comma === false ? $dataUri : substr($dataUri, $comma + 1), true);
        $binary = $binary === false ? '' : $binary;

        if ($saveDebugImage && $binary !== '') {
            Storage::disk('local')->put(
                'temp/stroke_render/' . bin2hex(random_bytes(16)) . '.png',
                $binary,
            );
        }

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Не удалось создать поток для PNG');
        }

        fwrite($stream, $binary);
        rewind($stream);

        return $stream;
    }
}
