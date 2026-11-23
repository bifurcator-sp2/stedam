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
        //каждый из learn->questions имеет index = order блока + 1 (номер задания в уроке, 1-based)
        $procedureTests = $grade->learn->questions;

        // Один декод на весь пайплайн: дальше всё идёт по ссылке (&$snapshot),
        // без копий массива на каждом шаге — в долгоживущем воркере это
        // иначе даёт 2-3 копии JSON снапшота плюс base64 всех картинок.
        $snapshot = is_array($snapshotJson)
            ? $snapshotJson
            : json_decode((string) $snapshotJson, true);

        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return [];
        }

        self::fillProcTests($snapshot, $procedureTests);

        //массив объектов с ответами ученика на все задания в уроке
        //естественный порядок ответов строго соответствует порядку блоков в уроке:
        //ответ на позиции i адресует блок с order = i.
        //Берём сырой JSON из Eloquent-модели: whiteboard-блоки не содержат index,
        //и доменный LearnAnswers (только {index, file}) их бы отбросил.
        $studentAnswers = $grade->learn->getAttribute('answers');

        $this->fillStudentAnswers($snapshot, $studentAnswers);
        unset($snapshotJson, $procedureTests, $studentAnswers);

        return self::extractTestsWithQuestionsEthalonsAndStudentAnswers($snapshot);
    }

    /*
     * Находит в снапшоте процедурные задания по признаку is_proc
     * заполняет ими содержание процедурных заданий из $procedureTests
     *
     * $procedureTests — массив learn->questions: [{index, question, answer, solution, ...}],
     * где index = order блока + 1 (номер задания в уроке, 1-based; та же
     * нумерация, что у answers[].index, см. LearnQuestionsBuilder).
     * В снапшоте генеративный текст proc-узла обнулён ассемблером, поэтому
     * условие задачи и эталон подставляются из $procedureTests.
     *
     * Модифицирует переданный snapshot по ссылке (без копии массива).
     * */
    public static function fillProcTests(&$snapshot, $procedureTests): void
    {
        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return;
        }

        $byIndex = [];
        foreach ((array) $procedureTests as $item) {
            if (is_array($item) && isset($item['index'])) {
                $byIndex[(int) $item['index']] = $item;
            }
        }

        if ($byIndex === []) {
            return;
        }

        $position = 0;
        foreach ($snapshot['blocks'] as $k => $block) {
            $currentPosition = $position++;
            if (!is_array($block) || empty($block['is_proc'])) {
                continue;
            }

            $order = (int) ($block['order'] ?? $k);
            // index задания 1-based, order блока 0-based. У глобальных заданий
            // order = 0, поэтому их номер берём по позиции в снапшоте (= позиция
            // в Lesson::allBlocksOrdered(), из которой строится QuestionItem::index).
            $procedureTest = $byIndex[$order + 1] ?? $byIndex[$currentPosition + 1] ?? null;
            if ($procedureTest === null) {
                continue;
            }

            $snapshot['blocks'][$k]['settings'] = self::fillBlockSettings(
                is_array($block['settings'] ?? null) ? $block['settings'] : [],
                (string) ($procedureTest['question'] ?? ''),
                self::buildEthalon($procedureTest),
            );
        }
    }

    /*
     * Все задания, которые есть в снапшоте урока, заполняет ответами ученика
     * из $studentAnswers в строгом соответствии с их порядком.
     *
     * $studentAnswers — массив learn->answers: JSON-блоки whiteboard либо
     * [{index, file}] для ответов-картинок.
     *
     * Ответы на ВСЕ задания (а не только на процедурные) идут в натуральном
     * порядке блоков: ответ на позиции i адресует блок с order = i. Это та же
     * нумерация, что у вопросов (QuestionItem::index = order + 1) и у
     * AnswerImage::index: элемент с явным index ставится в блок order = index - 1.
     *
     * Изображение читается с диска ответов (config('learn.answers.disk'))
     * и кодируется в data URI, чтобы дальше уйти в нейросеть.
     *
     * Модифицирует переданный snapshot по ссылке (без копии массива).
     * */
    public function fillStudentAnswers(&$snapshot, $studentAnswers): void
    {
        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return;
        }

        if (!(array) $studentAnswers) {
            return;
        }

        $disk = Storage::disk((string) config('learn.answers.disk', 's3_public'));

        // $byIndex — только ответы с явным index (позиция задания в уроке, 1-based)
        $byIndex = [];
        // $positional — ответы без index, в том порядке, в котором пришли (1-based)
        $positional = [];
        foreach (array_values((array) $studentAnswers) as $position => $answer) {
            if (!is_array($answer)) {
                continue;
            }

            // answers может храниться и как [{index, file}], и как сырые блоки whiteboard
            if (isset($answer['index'], $answer['file'])) {
                $byIndex[(int) $answer['index']] = ['file' => (string) $answer['file'], 'strokes' => null];
                continue;
            }

            // Ответ на whiteboard хранится по-разному: блоком ({strokes: [...],
            // whiteboard_width, ...}), голым списком штрихов или одним штрихом.
            $entry = ['file' => null, 'strokes' => self::normalizeStrokeSource($answer)];
            if ($entry['strokes'] === null) {
                continue;
            }

            if (isset($answer['index'])) {
                $byIndex[(int) $answer['index']] = $entry;
            } else {
                $positional[] = $entry;
            }
        }

        // Ответы без index (whiteboard-блоки) адресуют задания по их порядку:
        // ответ на позиции i относится к i-му заданию урока. Задание — это блок
        // с формулировкой (testLabel, его ставит процедурное задание) или
        // процедурный блок (is_proc). Опираться на order/позицию среди ВСЕХ
        // блоков нельзя: между заданиями стоят статические блоки, а у глобальных
        // заданий из pivot order вообще равен 0, из-за чего позиционные ответы
        // раньше уезжали в статические блоки 0..4 вместо заданий 20..24.
        $taskPositions = [];
        foreach ($snapshot['blocks'] as $k => $block) {
            if (!self::isLessonTask($block)) {
                continue;
            }
            $taskPositions[] = $k;
        }

        $answerIndex = 0;
        foreach ($snapshot['blocks'] as $k => $block) {
            if (!is_array($block)) {
                continue;
            }

            $order = (int) ($block['order'] ?? $k);
            // Явный index адресует задание по order + 1 (его проставляет клиент),
            // позиционный ответ — по порядку заданий в уроке.
            $answer = $byIndex[$order + 1] ?? null;
            if ($answer === null && ($taskPositions[$answerIndex] ?? null) === $k) {
                $answer = $positional[$answerIndex] ?? null;
                $answerIndex++;
            }

            if ($answer === null) {
                continue;
            }

            if ($answer['strokes'] !== null) {
                $source = $answer['strokes'];

                $strokeBlocks = is_array($source['strokes'] ?? null) ? $source['strokes'] : [];

                $width  = (int) ($source['whiteboard_width'] ?? 854);
                $height = (int) ($source['whiteboard_height'] ?? 314);

                $block['student_answer'] = [
                    'strokeBlocks' => $strokeBlocks,
                    'width'        => $width,
                    'height'       => $height,
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

            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($binary);
            unset($binary);

            $block['student_answer'] = [
                'file'     => $file,
                'mime'     => $mime,
                'data_uri' => $dataUri,
            ];

            $snapshot['blocks'][$k] = $block;
        }
    }

    /**
     * Приводит ответ на whiteboard к блоку {strokes: [...], whiteboard_width, whiteboard_height}.
     *
     * Поддерживаются три формы: блок whiteboard, плоский список штрихов
     * и один штрих без обёртки. null — если это не ответ на whiteboard.
     *
     * @param mixed $answer
     * @return array<string, mixed>|null
     */
    private static function normalizeStrokeSource($answer): ?array
    {
        if (!is_array($answer)) {
            return null;
        }

        // блок whiteboard
        if (isset($answer['strokes']) && is_array($answer['strokes'])) {
            return $answer;
        }

        // плоский список штрихов
        if (array_is_list($answer) && isset($answer[0]['points']) && is_array($answer[0]['points'])) {
            return ['strokes' => $answer];
        }

        // один штрих без обёртки
        if (isset($answer['points']) && is_array($answer['points'])) {
            return ['strokes' => [$answer]];
        }

        return null;
    }

    /*
     * Забирает из полностью актуализированного снапшта с заданиями и ответами ученика данные для отправки на оценку нейросетью
     *
     * */
    public static function extractTestsWithQuestionsEthalonsAndStudentAnswers($snapshotWithsFilledProcTestsAndStudentAnswers): array
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

            // Задание — это блок с формулировкой (testLabel). У обычных статических
            // блоков и у блоков-заглушек с is_test=true testLabel нет, хотя в
            // настройках может лежать посторонний ethalon. Без такой проверки в
            // оценку попадали блоки с чужим эталоном-заглушкой.
            if (self::extractText($block['settings'] ?? null) === '') {
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
                // Ответ ученика либо картинкой ({file, mime, data_uri}),
                // либо штрихами ({strokeBlocks, width, height}). Отдаём
                // структуру как есть: MLpregrader сам решает, рендерить ли
                // штрихи или брать готовый data URI.
                'student_answer' => is_array($studentAnswer) ? $studentAnswer : null,
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
     * Задание ли это: блок с формулировкой (testLabel) либо процедурный блок.
     *
     * @param mixed $block
     */
    private static function isLessonTask($block): bool
    {
        if (!is_array($block)) {
            return false;
        }

        if (!empty($block['is_proc'])) {
            return true;
        }

        return self::extractText($block['settings'] ?? null) !== '';
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
