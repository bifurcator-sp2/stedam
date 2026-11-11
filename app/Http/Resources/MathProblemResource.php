<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON-представление сгенерированного математического задания.
 *
 * Resource оборачивает MathProblem и добавляет ключ 'subject', чтобы
 * клиент получал полный контекст (предмет + тема + сложность).
 *
 * Поля:
 *  - question: текст задания с inline KaTeX (\\( ... \\));
 *  - solution: эталонное решение с display KaTeX (\\[ ... \\]);
 *  - answer:   чистый ответ (число, корень и т.п.);
 *  - topic:    ключ темы;
 *  - subject:  ключ предмета;
 *  - difficulty: сложность 0.0 … 1.0.
 *
 * @mixin MathProblem
 */
final class MathProblemResource extends JsonResource
{
    /**
     * @param MathProblem $resource
     * @param string      $subject Ключ предмета (передаётся из контроллера).
     */
    public function __construct(MathProblem $resource, private readonly string $subject)
    {
        parent::__construct($resource);
    }

/**
 * @return array<string, mixed>
 */
public function toArray(Request $request): array
{
    /** @var MathProblem $problem */
    $problem = $this->resource;

    return [
        'question'   => $problem->question,
        'solution'   => $problem->solution,
        'answer'     => $problem->answer,
        'topic'      => $problem->topic,
        'subject'    => $this->subject,
        'difficulty' => $problem->difficulty,
    ];
}
}
