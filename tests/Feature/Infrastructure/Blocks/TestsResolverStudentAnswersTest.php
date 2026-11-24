<?php

namespace Tests\Feature\Infrastructure\Blocks;

use App\Infrastructure\Blocks\TestsResolver;
use App\Infrastructure\Grade\StrokesRender;
use Tests\TestCase;

/**
 * Ответы ученика (JSON-блоки whiteboard) адресуют задания по их порядку в уроке:
 * ответ на позиции i относится к i-му заданию. Задание — блок с формулировкой
 * (testLabel) или процедурный блок (is_proc). Нумерация в questions[].index —
 * 1-based позиция блока в полном списке блоков урока, но она не совпадает с
 * порядковым номером задания, потому что между заданиями есть статические блоки.
 */
class TestsResolverStudentAnswersTest extends TestCase
{
    private function stroke(string $id): array
    {
        return [
            'id'      => $id,
            'size'    => 2,
            'color'   => '#000000',
            'eraser'  => false,
            'points'  => [['x' => 10.0, 'y' => 10.0], ['x' => 20.0, 'y' => 20.0]],
        ];
    }

    private function whiteboardAnswer(array $strokes, int $width, int $height): array
    {
        return [
            'strokes'           => $strokes,
            'version'           => 1,
            'answer_size'       => 2,
            'margin_size'       => 10,
            'whiteboard_width'  => $width,
            'whiteboard_height' => $height,
        ];
    }

    /**
     * Настройки задания с формулировкой и эталоном.
     */
    private function taskSettings(string $label, string $ethalon = '42'): array
    {
        return [
            'key'      => 'content',
            'children' => [
                ['key' => 'random', 'children' => [['key' => 'testLabel']], 'default' => $label],
                ['key' => 'ethalon', 'default' => $ethalon],
            ],
        ];
    }

    public function test_answers_are_matched_to_tasks_in_their_order(): void
    {
        $resolver = $this->resolver();

        $snapshot = [
            'blocks' => [
                ['order' => 0, 'is_test' => false, 'settings' => []],
                ['order' => 1, 'is_test' => true, 'settings' => $this->taskSettings('task1')],
                ['order' => 2, 'is_test' => false, 'settings' => []],
                ['order' => 3, 'is_test' => true, 'is_proc' => true, 'settings' => []],
                ['order' => 4, 'is_test' => true, 'settings' => $this->taskSettings('task2')],
            ],
        ];
        $answers = [
            $this->whiteboardAnswer([$this->stroke('a')], 854, 234),
            $this->whiteboardAnswer([$this->stroke('b'), $this->stroke('c')], 854, 130),
            $this->whiteboardAnswer([$this->stroke('d')], 854, 100),
        ];

        $resolver->fillStudentAnswers($snapshot, $answers);

        // Задания: order 1, 3, 4. Ответ 0 → order 1, ответ 1 → order 3,
        // ответ 2 → order 4. Статический блок order 2 остаётся пустым.
        $this->assertSame(1, count($snapshot['blocks'][1]['student_answer']['strokeBlocks']));
        $this->assertSame(234, $snapshot['blocks'][1]['student_answer']['height']);
        $this->assertSame(2, count($snapshot['blocks'][3]['student_answer']['strokeBlocks']));
        $this->assertSame('d', $snapshot['blocks'][4]['student_answer']['strokeBlocks'][0]['id']);
        $this->assertArrayNotHasKey('student_answer', $snapshot['blocks'][0]);
        $this->assertArrayNotHasKey('student_answer', $snapshot['blocks'][2]);
    }

    /**
     * Боевой расклад: статические блоки 0..19, затем задания 20..24. Все задания
     * помечены is_proc, поэтому их номера в questions[].index — 21..25. Ответы
     * идут по порядку заданий, а не по их позиции среди всех блоков.
     */
    public function test_positional_answers_reach_their_own_tasks_not_the_first_blocks(): void
    {
        $resolver = $this->resolver();

        $blocks = [];
        for ($order = 0; $order < 25; $order++) {
            if ($order >= 20) {
                $block = [
                    'order'    => $order,
                    'is_test'  => true,
                    'is_proc'  => true,
                    'settings' => [],
                ];
            } else {
                $block = ['order' => $order, 'is_test' => false, 'settings' => []];
            }
            $blocks[] = $block;
        }
        $snapshot = ['blocks' => $blocks];

        $answers = [];
        for ($i = 0; $i < 5; $i++) {
            $answers[] = $this->whiteboardAnswer([$this->stroke('s'.$i)], 854, 100);
        }

        $resolver->fillStudentAnswers($snapshot, $answers);

        // Статические блоки 0..19 не получают ответов.
        for ($order = 0; $order < 20; $order++) {
            $this->assertArrayNotHasKey('student_answer', $snapshot['blocks'][$order]);
        }

        // Ответ на позиции i попадает в задание order 20 + i.
        for ($i = 0; $i < 5; $i++) {
            $this->assertArrayHasKey('student_answer', $snapshot['blocks'][20 + $i]);
            $this->assertSame(
                's'.$i,
                $snapshot['blocks'][20 + $i]['student_answer']['strokeBlocks'][0]['id'],
            );
        }
    }

    /**
     * Глобальные задания из pivot приходят со order = 0, но идут в конце списка
     * блоков. Ответы должны адресоваться по их позиции в уроке, а не по order.
     */
    public function test_global_tasks_with_zero_order_get_answers_by_position(): void
    {
        $resolver = $this->resolver();

        $snapshot = [
            'blocks' => [
                ['order' => 0, 'is_test' => false, 'settings' => []],
                ['order' => 1, 'is_test' => true, 'settings' => $this->taskSettings('own-task')],
                ['order' => 0, 'is_test' => true, 'is_proc' => true, 'settings' => []],
                ['order' => 0, 'is_test' => true, 'is_proc' => true, 'settings' => []],
            ],
        ];
        $answers = [
            $this->whiteboardAnswer([$this->stroke('own')], 854, 100),
            $this->whiteboardAnswer([$this->stroke('g1')], 854, 100),
            $this->whiteboardAnswer([$this->stroke('g2')], 854, 100),
        ];

        $resolver->fillStudentAnswers($snapshot, $answers);

        $this->assertSame('own', $snapshot['blocks'][1]['student_answer']['strokeBlocks'][0]['id']);
        $this->assertSame('g1', $snapshot['blocks'][2]['student_answer']['strokeBlocks'][0]['id']);
        $this->assertSame('g2', $snapshot['blocks'][3]['student_answer']['strokeBlocks'][0]['id']);
        $this->assertArrayNotHasKey('student_answer', $snapshot['blocks'][0]);
    }

    public function test_flat_stroke_list_and_single_stroke_are_wrapped(): void
    {
        $resolver = $this->resolver();

        $snapshot = [
            'blocks' => [
                ['order' => 0, 'is_test' => false, 'settings' => []],
                ['order' => 1, 'is_test' => true, 'is_proc' => true, 'settings' => []],
                ['order' => 2, 'is_test' => true, 'is_proc' => true, 'settings' => []],
                ['order' => 3, 'is_test' => true, 'is_proc' => true, 'settings' => []],
            ],
        ];
        $answers = [
            $this->whiteboardAnswer([$this->stroke('a'), $this->stroke('b')], 854, 130),
            [$this->stroke('c')],
            $this->stroke('d'),
        ];

        $resolver->fillStudentAnswers($snapshot, $answers);

        $this->assertSame(2, count($snapshot['blocks'][1]['student_answer']['strokeBlocks']));
        $this->assertSame(854, $snapshot['blocks'][1]['student_answer']['width']);
        $this->assertSame(130, $snapshot['blocks'][1]['student_answer']['height']);

        // второй ответ — плоский список штрихов, третий — один голый штрих
        $this->assertSame(1, count($snapshot['blocks'][2]['student_answer']['strokeBlocks']));
        $this->assertSame(1, count($snapshot['blocks'][3]['student_answer']['strokeBlocks']));
        $this->assertSame('d', $snapshot['blocks'][3]['student_answer']['strokeBlocks'][0]['id']);
    }

    public function test_explicit_answer_index_wins_over_position(): void
    {
        $resolver = $this->resolver();

        $snapshot = [
            'blocks' => [
                ['order' => 0, 'is_test' => false, 'settings' => []],
                ['order' => 1, 'is_test' => true, 'settings' => $this->taskSettings('task1')],
                ['order' => 2, 'is_test' => true, 'settings' => $this->taskSettings('task2')],
            ],
        ];
        // Явный index адресует блок по order + 1: order 1 => index 2, order 2 => index 3.
        $answers = [
            $this->whiteboardAnswer([$this->stroke('a')], 854, 130) + ['index' => 3],
            $this->whiteboardAnswer([$this->stroke('b')], 854, 234) + ['index' => 2],
        ];

        $resolver->fillStudentAnswers($snapshot, $answers);

        $this->assertSame('a', $snapshot['blocks'][2]['student_answer']['strokeBlocks'][0]['id']);
        $this->assertSame(130, $snapshot['blocks'][2]['student_answer']['height']);
        $this->assertSame('b', $snapshot['blocks'][1]['student_answer']['strokeBlocks'][0]['id']);
        $this->assertSame(234, $snapshot['blocks'][1]['student_answer']['height']);
    }

    public function test_stroke_answer_survives_extract_stage(): void
    {
        $resolver = $this->resolver();

        $snapshot = [
            'blocks' => [
                ['order' => 0, 'is_test' => true, 'settings' => $this->taskSettings('task1')],
            ],
        ];

        $resolver->fillStudentAnswers($snapshot, [
            $this->whiteboardAnswer([$this->stroke('a')], 854, 130),
        ]);

        $tests = TestsResolver::extractTestsWithQuestionsEthalonsAndStudentAnswers($snapshot);

        $this->assertCount(1, $tests);
        $this->assertSame('task1', $tests[0]['task']);
        $this->assertIsArray($tests[0]['student_answer']);
        $this->assertArrayNotHasKey('data_uri', $tests[0]['student_answer']);
        $this->assertCount(1, $tests[0]['student_answer']['strokeBlocks']);
    }

    public function test_extract_skips_blocks_without_task_formulation(): void
    {
        // Блоки с is_test=true, но без testLabel — не задания, даже если в
        // настройках лежит посторонний эталон-заглушка.
        $snapshot = [
            'blocks' => [
                ['order' => 0, 'is_test' => true, 'settings' => ['children' => [['key' => 'ethalon', 'default' => 'заглушка']]]],
                ['order' => 1, 'is_test' => true, 'settings' => $this->taskSettings('real')],
            ],
        ];

        $tests = TestsResolver::extractTestsWithQuestionsEthalonsAndStudentAnswers($snapshot);

        $this->assertCount(1, $tests);
        $this->assertSame(1, $tests[0]['order']);
        $this->assertSame('real', $tests[0]['task']);
    }

    private function resolver(): TestsResolver
    {
        return new TestsResolver(
            app(StrokesRender::class),
            app(\App\Application\Learn\Ports\LearnRepositoryInterface::class),
            app(\App\Application\Learn\Ports\SnapshotReaderInterface::class),
        );
    }
}
