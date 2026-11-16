<?php

declare(strict_types=1);

namespace App\Domain\Lessons\ValueObjects;

/**
 * Нормализованный полнотекстовый поисковый запрос по урокам.
 *
 * Хранит:
 *   — normalized() — «человеческие» слова (lowercase, схлопнутые пробелы);
 *     это значение показывается/логируется как есть;
 *   — toTsQuery()  — синтаксис tsquery для PostgreSQL (prefix-AND):
 *     `+word1:* +word2:*`.
 *
 * Синтаксис префикса в PostgreSQL — `word:*` (двоеточие + звёздочка),
 * а не `word*`: последний не является prefix-оператором и даёт пустой
 * результат. Поэтому каждый токен превращается в `<слово>:*` и
 * склеивается через `+` (обязательное вхождение всех слов).
 *
 * Почему VO в домене: правила «что пользователь ввёл и какие слова ищутся»
 * — это предметная логика поиска урока. Как именно их превратить
 * в SQL (to_tsvector / to_tsquery, конфигурация 'simple') решает
 * инфраструктура.
 *
 * Инварианты:
 *   — пустой/состоящий только из символов запрос недопустим (DomainException);
 *   — операторы tsquery (+, -, *, &, |, !, :, '), кавычки, скобки,
 *     спецсимволы — вырезаются: пользовательский ввод не должен
 *     менять структуру tsquery.
 */
final readonly class FullTextSearchQuery
{
    /** Символы/операторы целевого языка запросов, которые вырезаем из ввода. */
    private const STRIP_PATTERN = '/[+\-><()~*"@&|!:\'\\\\]+/u';

    private function __construct(
        private string $normalized,
    ) {}

    /**
     * @throws \DomainException если после нормализации не осталось слов
     */
    public static function fromUserInput(string $raw): self
    {
        $clean = preg_replace(self::STRIP_PATTERN, ' ', $raw) ?? '';
        $normalized = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ($normalized === '') {
            throw new \DomainException('Поисковый запрос не содержит значимых символов.');
        }

        return new self(mb_strtolower($normalized));
    }

    /** @return list<string> */
    public function words(): array
    {
        return explode(' ', $this->normalized);
    }

    public function normalized(): string
    {
        return $this->normalized;
    }

    /**
     * tsquery с префиксным поиском по каждому слову и обязательным
     * совпадением всех слов: `+msq:* +node:*`.
     *
     * Префикс задаётся двоеточием (`:*`) — без него `to_tsquery`
     * воспринимает звёздочку как обычный символ и не находит ничего.
     */
    public function toTsQuery(): string
    {
        return implode(' & ', array_map(
            static fn (string $word): string => $word.':*',
            $this->words(),
        ));
    }
}
