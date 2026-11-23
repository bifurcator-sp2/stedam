<?php

namespace App\Infrastructure\Blocks;

use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Infrastructure\Grade\StrokesRender;
use App\Models\LearnGrade;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TestsResolver
{
    public function __construct(
        private readonly StrokesRender $render,
        private readonly LearnRepositoryInterface $learns,
        private readonly SnapshotReaderInterface $snapshots,
    ) {
    }

    public function resolveTestsWithAnswers(LearnGrade $grade): array
    {
        $learn = $grade->learn;
        if ($learn === null) {
            throw new LearnNotFound("Learn {$grade->learn_id} not found");
        }
        $hash = new SnapshotHash($learn->lesson_snapshot);
        if ($hash->value === null) {
            throw new RuntimeException("Learn {$grade->learn_id} has no snapshot");
        }
        $snapshotJson = $this->snapshots->read($hash);

        //процедурные задания, если они есть в уроке
        //каждый из learn->questions имеет index. Это положение задания в уроке, начинается с 1
        $procedureTests = $grade->learn->questions;

        $snapshotWithsFilledProcTests = self::fillProcTests($snapshotJson, $procedureTests);

        //массив объектов с ответами ученика на все задания в уроке
        //естественный порядок ответов стрго соответствует порядку заданий в уроке
        $studentAnswers = $grade->learn->answers;

        $snapshotWithsFilledProcTestsAndStudentAnswers = $this->fillStudentAnswers($snapshotWithsFilledProcTests, $studentAnswers);

        $allTestsWithQuestionsEthalonsAndStudentAnswers = self::extractTestsWithQuestionsEthalonsAndStudentAnswers($snapshotWithsFilledProcTestsAndStudentAnswers);

        return $allTestsWithQuestionsEthalonsAndStudentAnswers;

    }

    /*
     * Находит в снапшоте процедурные задания по признаку is_proc
     * заполняет ими содержание процедурных заданий из $procedureTests
     *
     * $procedureTests — массив learn->questions: [{index, question, answer, solution, ...}],
     * где index — положение задания в уроке, начиная с 1 (order блока + 1).
     * В снапшоте генеративный текст proc-узла обнулён ассемблером, поэтому
     * условие задачи и эталон подставляются из $procedureTests.
     * */
    public static function fillProcTests($snapshotJson, $procedureTests)
    {
        $snapshot = is_array($snapshotJson) ? $snapshotJson : json_decode((string) $snapshotJson, true);
        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return $snapshotJson;
        }

        $byIndex = [];
        foreach ((array) $procedureTests as $item) {
            if (is_array($item) && isset($item['index'])) {
                $byIndex[(int) $item['index']] = $item;
            }
        }

        foreach ($snapshot['blocks'] as $k => $block) {
            if (!is_array($block) || empty($block['is_proc'])) {
                continue;
            }

            $order = (int) ($block['order'] ?? $k);
            // index задания 1-based, order блока 0-based
            $procedureTest = $byIndex[$order + 1] ?? null;
            if ($procedureTest === null) {
                continue;
            }

            $block['settings'] = self::fillBlockSettings(
                is_array($block['settings'] ?? null) ? $block['settings'] : [],
                (string) ($procedureTest['question'] ?? ''),
                self::buildEthalon($procedureTest),
            );

            $snapshot['blocks'][$k] = $block;
        }

        // массивы blocks/children после фильтра сохраняют дырявые ключи — нормализуем
        $snapshot['blocks'] = array_values($snapshot['blocks']);

        return $snapshot;
    }

    /*
     * Все задания которые есть снапшоте урока заполняет ответами ученика из $studentAnswers в строгом сответствии с их порядком
     *
     * $studentAnswers — массив learn->answers: [{index, file}], index 1-based
     * соответствует order блока + 1. Изображение читается с диска ответов
     * (config('learn.answers.disk')) и кодируется в data URI, чтобы дальше
     * уйти в нейросеть.
     * */
    public function fillStudentAnswers($snapshotWithsFilledProcTests, $studentAnswers)
    {
        $snapshot = is_array($snapshotWithsFilledProcTests) ? $snapshotWithsFilledProcTests : json_decode((string) $snapshotWithsFilledProcTests, true);
        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return $snapshotWithsFilledProcTests;
        }

        if (!(array) $studentAnswers) {
            return $snapshotWithsFilledProcTests;
        }

        $disk = Storage::disk((string) config('learn.answers.disk', 's3_public'));

        $byIndex = [];
        foreach (array_values((array) $studentAnswers) as $position => $answer) {
            if (!is_array($answer)) {
                continue;
            }

            // answers может храниться и как [{index, file}], и как сырые блоки whiteboard
            if (isset($answer['index'], $answer['file'])) {
                $byIndex[(int) $answer['index']] = ['file' => (string) $answer['file'], 'strokes' => null];
                continue;
            }

            if (isset($answer['strokes']) && is_array($answer['strokes'])) {
                // если index не сохранён, блок whiteboard идёт по порядку (1-based)
                $index = isset($answer['index']) ? (int) $answer['index'] : $position + 1;
                $byIndex[$index] = ['file' => null, 'strokes' => $answer];
            }
        }

        foreach ($snapshot['blocks'] as $k => $block) {
            if (!is_array($block) || empty($block['is_test'])) {
                continue;
            }

            $order  = (int) ($block['order'] ?? $k);
            $answer = $byIndex[$order + 1] ?? null;
            if ($answer === null) {
                continue;
            }

            if ($answer['strokes'] !== null) {
                // strokes может быть плоским списком штрихов, а может — списком блоков whiteboard
                $strokes = $answer['strokes'];
                if (isset($strokes['points'])) {
                    $strokes = [$strokes];
                }
                $strokeBlocks = array_values($strokes);
                if (is_array($strokeBlocks[0]['strokes'] ?? null)) {
                    $strokeBlocks = $strokeBlocks[0]['strokes'];
                }

                $width  = (int) ($answer['whiteboard_width'] ?? 854);
                $height = (int) ($answer['whiteboard_height'] ?? 314);
                //$svg    = $this->render->buildSvg($strokeBlocks, $width, $height);


                    $block['student_answer'] = [
                        'strokeBlocks'=>$strokeBlocks,
                        'width'=>$width,
                        'height'=>$height
                    ];

                    $snapshot['blocks'][$k] = $block;


                continue;
            }

            $file = $answer['file'];
            if ($file === null || !$disk->exists($file)) {
                continue;
            }

            $binary = $disk->get($file);
            if ($binary === null || $binary === '') {
                continue;
            }

            $mime = $disk->mimeType($file) ?: (str_ends_with(strtolower($file), '.webp') ? 'image/webp' : 'image/png');

            $block['student_answer'] = [
                'file'     => $file,
                'mime'     => $mime,
                'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($binary),
            ];

            $snapshot['blocks'][$k] = $block;
        }

        $snapshot['blocks'] = array_values($snapshot['blocks']);

        return $snapshot;
    }

    /*
     * Забирает из полностью актуализированного снапшта с заданиями и ответами ученика данные для отправки на оценку нейросетью
     *
     * */
    public static function extractTestsWithQuestionsEthalonsAndStudentAnswers($snapshotWithsFilledProcTestsAndStudentAnswers)
    {
        /* формат элемена списка
         *  [
                'order'=>123, //индекс блока задания в снапшоте урока
                'complexity'=>0.225, // сдлжность задания
                'task'=>'формулировка вопроса задания',
                'solution'=>'идеальное решени с ответом',
                'student_answer'=>'Решение ученика с ответом'
            ]
         * */

        $snapshot = is_array($snapshotWithsFilledProcTestsAndStudentAnswers)
            ? $snapshotWithsFilledProcTestsAndStudentAnswers
            : json_decode((string) $snapshotWithsFilledProcTestsAndStudentAnswers, true);
        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return [];
        }

        $allTestsWithQuestionsEthalonsAndStudentAnswers = [];

        foreach ($snapshot['blocks'] as $k => $block) {
            if (!is_array($block) || empty($block['is_test'])) {
                continue;
            }

            $ethalon = self::extractEthalon($block['settings'] ?? null);
            if ($ethalon === null) {
                continue;
            }

            $studentAnswer = $block['student_answer'] ?? null;

            $allTestsWithQuestionsEthalonsAndStudentAnswers[] = [
                'order'          => (int) ($block['order'] ?? $k),
                'complexity'     => (float) ($block['complexity'] ?? 0.0),
                'task'           => self::extractText($block['settings'] ?? null),
                'solution'       => $ethalon,
                'student_answer' => is_array($studentAnswer) ? (string) ($studentAnswer['data_uri'] ?? '') : '',
            ];
        }

        return $allTestsWithQuestionsEthalonsAndStudentAnswers;
    }

    /**
     * Заполняет proc-узлы текстом задачи и эталоном внутри настроек блока.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function fillBlockSettings(array $node, string $question, string $ethalon): array
    {
        if (isset($node['key'])) {
            if ($node['key'] === 'random' && self::hasChildKey($node, 'testLabel')) {
                $node['default'] = $question;
            }

            // узел test в content-blocks: ethalon лежит ребёнком, а не полем
            if ($node['key'] === 'test' && self::hasChildKey($node, 'ethalon')) {
                foreach ((array) ($node['children'] ?? []) as $k => $child) {
                    if (is_array($child) && ($child['key'] ?? null) === 'ethalon') {
                        $node['children'][$k]['default'] = $ethalon;
                    }
                }
            }
        }

        foreach ((array) ($node['children'] ?? []) as $k => $child) {
            if (is_array($child)) {
                $node['children'][$k] = self::fillBlockSettings($child, $question, $ethalon);
            }
        }

        return $node;
    }

    /**
     * Эталонное решение с ответом — то, что видит ученик как правильный ответ.
     *
     * @param array<string, mixed> $procedureTest
     */
    private static function buildEthalon(array $procedureTest): string
    {
        $solution = trim((string) ($procedureTest['solution'] ?? ''));
        $answer   = trim((string) ($procedureTest['answer'] ?? ''));

        if ($answer !== '' && !str_contains($solution, $answer)) {
            $solution .= "\n\nОтвет: {$answer}";
        }

        return $solution;
    }

    /**
     * Текст эталона: sibling-поле 'ethalon' либо дочерний узел с key='ethalon'.
     *
     * @param mixed $node
     */
    private static function extractEthalon($node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        if (isset($node['ethalon']) && is_string($node['ethalon'])) {
            return $node['ethalon'];
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child) && ($child['key'] ?? null) === 'ethalon') {
                return (string) ($child['default'] ?? '');
            }
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            $found = self::extractEthalon($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Достаёт текст proc-узла (узел, среди детей которого есть 'testLabel').
     * Ассемблер снапшота обнуляет default таких узлов, поэтому сюда он попадает
     * только после fillProcTests.
     *
     * @param mixed $node
     */
    private static function extractText($node): string
    {
        if (!is_array($node)) {
            return '';
        }

        if (isset($node['key']) && self::hasChildKey($node, 'testLabel')) {
            return (string) ($node['default'] ?? '');
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            $text = self::extractText($child);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function hasChildKey(array $node, string $key): bool
    {
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child) && ($child['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

}
