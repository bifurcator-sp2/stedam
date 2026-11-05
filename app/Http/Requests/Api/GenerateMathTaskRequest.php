<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация запроса на генерацию случайного математического задания.
 *
 * Параметры (все опциональны):
 *  - subject:    ключ предмета; если не задан — берётся config('test_generator.default_subject').
 *  - topic:      ключ темы; если не задан — выбирается случайная тема предмета.
 *  - difficulty: 0.0 … 1.0; если не задан — config('test_generator.default_difficulty').
 *
 * Валидация строгая для known ключей (subject — из конфига, topic — из каталога
 * указанного subject). Существование топика в TestGenerator проверяется уже
 * в контроллере, чтобы не дублировать реестр стратегий в правилах валидации.
 */
final class GenerateMathTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subjects = array_keys((array) config('test_generator.subjects', []));
        $subject  = $this->resolvedSubject();

        $topics = [];
        if ($subject !== null) {
            $topics = array_keys(
                (array) config("test_generator.subjects.{$subject}.topics", [])
            );
        }

        return [
            'subject' => [
                'sometimes',
                'string',
                Rule::in($subjects),
            ],
            'topic' => [
                'sometimes',
                'string',
                $topics === []
                    ? 'prohibited'          // если у предмета нет тем — не разрешаем
                    : Rule::in($topics),
            ],
            'difficulty' => [
                'sometimes',
                'numeric',
                'between:0,1',
            ],
        ];
    }

    /**
     * Человекочитаемые названия полей — для сообщений об ошибках.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subject'    => 'предмет',
            'topic'      => 'тема',
            'difficulty' => 'сложность',
        ];
    }

    /**
     * Ключ предмета после применения дефолта.
     *
     * Возвращает null, если в запросе передан неизвестный subject
     * (тогда валидация всё равно вернёт ошибку по правилу Rule::in).
     */
    public function resolvedSubject(): ?string
    {
        $subject = $this->input('subject');

        if (is_string($subject) && $subject !== '') {
            return $subject;
        }

        $default = config('test_generator.default_subject');

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * Ключ темы: либо из запроса, либо null (тогда контроллер выберет случайную).
     */
    public function resolvedTopic(): ?string
    {
        $topic = $this->input('topic');

        return is_string($topic) && $topic !== '' ? $topic : null;
    }

    /**
     * Сложность: либо из запроса (как float), либо дефолт из конфига, либо 0.5.
     */
    public function resolvedDifficulty(): float
    {
        $input = $this->input('difficulty');

        if ($input !== null && $input !== '') {
            return (float) $input;
        }

        $default = config('test_generator.default_difficulty');

        return is_numeric($default) ? (float) $default : 0.5;
    }
}
