<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {
        $settings = $this->stripPublicKeys($this->settings ?? []);
        $settings = $this->restorePublicFlags(
            $settings,
            $this->block?->settings ?? [],
        );

        return [
            'id'              => $this->id,
            'block_id'        => $this->block_id,
            'block_type_code' => $this->block?->blockType?->code,
            'settings'        => $settings,
            'order'           => $this->order,
        ];
    }

    /**
     * Рекурсивно удаляет все ключи `public` из дерева настроек
     * (независимо от значения и уровня вложенности).
     */
    protected function stripPublicKeys(array $settings): array
    {
        $result = [];

        foreach ($settings as $key => $value) {
            if ($key === 'public') {
                continue;
            }

            $result[$key] = is_array($value)
                ? $this->stripPublicKeys($value)
                : $value;
        }

        return $result;
    }

    /**
     * Рекурсивно обходит ВСЁ дерево настроек и возвращает `public: true`
     * в тех узлах, где в аналогичном месте эталона ($this->block->settings)
     * стояло `public === true`.
     *
     * Сопоставление узлов:
     *  - в списке узлов схемы (элемент с полем `key`) — по `key`;
     *  - в ассоциативном массиве — по тому же строковому ключу;
     *  - в прочих списках (например, `default` у Image) — по индексу.
     */
    protected function restorePublicFlags(array $settings, array $reference): array
    {
        $result = [];

        foreach ($settings as $key => $value) {
            if (!is_array($value)) {
                $result[$key] = $value;
                continue;
            }

            $refNode = $this->findReferenceNode($value, $key, $reference);

            // Рекурсия по всем вложенным массивам, а не только по children
            $node = $this->restorePublicFlags(
                $value,
                is_array($refNode) ? $refNode : [],
            );

            // Восстанавливаем public: true, если в эталоне на этом месте он был.
            // Списки (чисто числовые массивы) не трогаем — иначе сломается структура.
            if (
                is_array($refNode)
                && ($refNode['public'] ?? false) === true
                && !array_is_list($node)
            ) {
                $node['public'] = true;
            }

            $result[$key] = $node;
        }

        return $result;
    }

    /**
     * Ищет соответствующий узел в эталоне.
     *
     * @param mixed      $value     Текущее значение из настроек экземпляра.
     * @param int|string $key       Ключ, под которым $value лежит у родителя.
     * @param array      $reference Эталонный массив того же уровня.
     */
    protected function findReferenceNode(mixed $value, int|string $key, array $reference): ?array
    {
        // Эталон — список (schema-узлы, children, default-массивы и т.п.)
        if (array_is_list($reference)) {
            // Узел схемы: у него есть строковый `key` — матчим по нему
            if (isset($value['key']) && is_string($value['key'])) {
                foreach ($reference as $refItem) {
                    if (is_array($refItem) && ($refItem['key'] ?? null) === $value['key']) {
                        return $refItem;
                    }
                }
                return null;
            }

            // Обычный список — по индексу
            if (is_int($key) && isset($reference[$key]) && is_array($reference[$key])) {
                return $reference[$key];
            }

            return null;
        }

        // Эталон — ассоциативный массив: матчим по тому же ключу
        if (isset($reference[$key]) && is_array($reference[$key])) {
            return $reference[$key];
        }

        return null;
    }
}
