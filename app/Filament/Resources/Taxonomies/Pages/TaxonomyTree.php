<?php

namespace App\Filament\Resources\Taxonomies\Pages;

use App\Blocks\TestGenerator\Support\GeneratorOptions;
use App\Filament\Resources\Taxonomies\TaxonomyResource;
use App\Models\Taxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\FilamentTree\Actions\DeleteAction;
use SolutionForest\FilamentTree\Actions\EditAction;
use SolutionForest\FilamentTree\Resources\Pages\TreePage;

class TaxonomyTree extends TreePage
{
    protected static string $resource = TaxonomyResource::class;

    protected static int $maxDepth = 10;

    public ?int $rootId = null;

    public function mount(int $root): void
    {
        abort_unless(
            Taxonomy::where('id', $root)->where('parent_id', -1)->exists(),
            404
        );

        $this->rootId = $root;

        // Сворачиваем дерево при первой загрузке страницы.
        //
        // В пакете SolutionForest\FilamentTree нет свойства "по умолчанию
        // свернуто". Зато есть нативная кнопка "Collapse All", которая
        // сворачивает всё дерево через свой JS/Livewire. Кликаем её
        // программно после готовности DOM.
        //
        // setTimeout(..., 100) — даёт Livewire/Alpine время отрендерить
        // дерево; 100 мс надёжнее, чем $nextTick (которого нет в $this->js()).
        $this->js(<<<'JS'
            setTimeout(() => {
                const btn = Array.from(document.querySelectorAll('button')).find(
                    b => b.textContent.trim() === 'Collapse All'
                );
                if (btn) btn.click();
            }, 100);
        JS);
    }

    // ────────────────────────────────────────────────────────────────────
    // БЫСТРАЯ ЗАГРУЗКА
    // ────────────────────────────────────────────────────────────────────
    //
    // Раньше: getDescendantIds() делал while-цикл с N+1 запросов
    // (запрос на каждый уровень дерева). На глубоком дереве — десятки
    // запросов и заметная задержка.
    //
    // Теперь: descendantsAndSelf() из staudenmeir/laravel-adjacency-list
    // разворачивает всё ОДНИМ рекурсивным CTE (PostgreSQL WITH RECURSIVE).
    // Один запрос независимо от глубины.

    protected function getTreeQuery(): Builder
    {
        $ids = $this->getDescendantIds($this->rootId);

        return Taxonomy::query()
            ->withCount('children')
            ->with(['translations'])
            ->whereIn('id', $ids)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * Все потомки корня (включая сам корень) — одним CTE.
     *
     * @return int[]
     */
    protected function getDescendantIds(?int $rootId): array
    {
        if (! $rootId) {
            return [];
        }

        $root = Taxonomy::find($rootId);
        if ($root === null) {
            return [];
        }

        return $root->descendantsAndSelf()->pluck('id')->all();
    }

    // ────────────────────────────────────────────────────────────────────
    // МЕТКИ РЕЗОЛВЕРОВ
    // ────────────────────────────────────────────────────────────────────
    //
    // Пакет рендерит заголовок узла через свой blade — точечно
    // переопределить формат в этом файле нельзя без override view пакета.
    //
    // Что делаем: добавляем резолвер в breadcrumb страницы. Это даёт
    // контекст «в какой ветке нахожусь», но не показывает резолверы
    // соседних узлов.
    //
    // ВАЖНО: метод должен быть public — в Filament Page он public.

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = parent::getBreadcrumbs();

        if ($this->rootId !== null) {
            $root = Taxonomy::find($this->rootId);
            if ($root !== null) {
                $resolver = $this->resolveForNode($root);
                if ($resolver !== null) {
                    $label = app(GeneratorOptions::class)->labels()[$resolver] ?? $resolver;
                    $breadcrumbs[] = $label;
                }
            }
        }

        return $breadcrumbs;
    }

    /**
     * Резолвер для узла: свой, иначе — унаследованный от ближайшего предка.
     * Кешируется на время запроса.
     */
    private function resolveForNode(Taxonomy $node): ?string
    {
        /** @var array<int, string|null> $cache */
        static $cache = [];

        if (array_key_exists($node->id, $cache)) {
            return $cache[$node->id];
        }

        if (is_string($node->test_resolver) && $node->test_resolver !== '') {
            return $cache[$node->id] = $node->test_resolver;
        }

        $current = $node->parent_id;
        $guard   = 0;

        while ($current !== null && $current >= 0 && $guard++ < 64) {
            $parent = Taxonomy::find($current);
            if ($parent === null) {
                break;
            }
            if (is_string($parent->test_resolver) && $parent->test_resolver !== '') {
                return $cache[$node->id] = $parent->test_resolver;
            }
            $current = $parent->parent_id;
        }

        return $cache[$node->id] = null;
    }

    // ────────────────────────────────────────────────────────────────────
    // ДЕЙСТВИЯ (без изменений)
    // ────────────────────────────────────────────────────────────────────

    protected function getTreeActions(): array
    {
        $actions = parent::getTreeActions();

        foreach ($actions as $action) {
            if (in_array($action->getName(), ['edit', 'delete'], true)) {
                $action->authorize(fn (?Model $record = null) => $record !== null);
            }
        }

        return $actions;
    }

    protected function configureEditAction(EditAction $action): EditAction
    {
        return parent::configureEditAction($action)
            ->authorize(fn (?Model $record = null) => $record !== null);
    }

    protected function configureDeleteAction(DeleteAction $action): DeleteAction
    {
        return parent::configureDeleteAction($action)
            ->authorize(fn (?Model $record = null) => $record !== null);
    }
}
