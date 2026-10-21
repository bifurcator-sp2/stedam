<?php

namespace App\Blocks\Settings;

/**
 * Нарушение формы/структуры settings относительно схемы.
 *
 * Бросается в SettingsNormalizer::extractPublicDefaults() при фатальных
 * расхождениях shape — когда продолжать обход некорректно.
 */
class SettingsShapeException extends \RuntimeException
{
    /**
     * @param array   $path     Путь до проблемного узла (цепочка key), [] для корня
     * @param string  $reason   Человекочитаемое описание
     * @param mixed   $expected Что ожидалось
     * @param mixed   $actual   Что пришло
     */
    public function __construct(
        public readonly array $path,
        public readonly string $reason,
        public readonly mixed $expected = null,
        public readonly mixed $actual = null,
    ) {
        parent::__construct(sprintf(
            'Settings shape mismatch at [%s]: %s',
            implode('.', $path) ?: '<root>',
            $reason,
        ));
    }
}
