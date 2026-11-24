<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\ValueObjects;

/**
 * Сообщение для уведомления: разобранный результат getRequestForm()/
 * getApprovalForm() в единый VO (раздел 1, structure {title, text, approve}).
 *
 * Хранит только значения (не {type, value} обёртки), т.к. они нужны
 * исключительно для рендера текста уведомления.
 */
final readonly class ApprovalMessage
{
    public function __construct(
        public string $title,
        public string $text,
        public bool $approve = false,
    ) {}

    /**
     * Разбирает структуру формы из section 1 ({title:{type,value},...}).
     *
     * @param  array<string, mixed>  $form
     */
    public static function fromForm(array $form): self
    {
        return new self(
            title: (string) self::valueOf($form['title'] ?? null, ''),
            text: (string) self::valueOf($form['text'] ?? null, ''),
            approve: (bool) self::valueOf($form['approve'] ?? null, false),
        );
    }

    /**
     * @return array{title: string, text: string, approve: bool}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'text' => $this->text,
            'approve' => $this->approve,
        ];
    }

    /**
     * Поле формы может прийти как {type, value} или как «голое» значение.
     */
    private static function valueOf(mixed $field, mixed $default): mixed
    {
        if (is_array($field) && array_key_exists('value', $field)) {
            return $field['value'] ?? $default;
        }

        return $field ?? $default;
    }
}
