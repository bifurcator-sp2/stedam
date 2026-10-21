<?php

namespace App\Filament\Resources\Taxonomies\Pages;

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
    }

    /**
     * Загружаем корень И ВСЕХ его потомков (на любую глубину).
     */
    protected function getTreeQuery(): Builder
    {
        $descendantIds = $this->getDescendantIds($this->rootId);

        return Taxonomy::query()
            ->withCount('children')
            ->with(['translations'])
            ->whereIn('id', $descendantIds);
    }

    /**
     * Рекурсивно собирает ID корня и всех его потомков.
     */
    protected function getDescendantIds(?int $rootId): array
    {
        if (!$rootId) {
            return [];
        }

        $ids = [$rootId];
        $parentIds = [$rootId];

        while (!empty($parentIds)) {
            $parentIds = Taxonomy::whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->toArray();

            $ids = array_merge($ids, $parentIds);
        }

        return $ids;
    }

    /**
     * Переопределяем действия, чтобы защитить их от null-записей.
     */
    protected function getTreeActions(): array
    {
        $actions = parent::getTreeActions();

        foreach ($actions as $action) {
            if ($action->getName() === 'edit' || $action->getName() === 'delete') {
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
