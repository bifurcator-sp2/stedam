<?php

namespace App\Filament\Resources\BlockIndexes\Pages;

use App\Filament\Resources\BlockIndexes\BlockIndexResource;
use App\Filament\Resources\BlockIndexes\Actions\AddChildAction;
use App\Models\BlockBlockIndex;
use App\Models\BlockIndex;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use SolutionForest\FilamentTree\Actions\DeleteAction;
use SolutionForest\FilamentTree\Actions\EditAction;
use SolutionForest\FilamentTree\Resources\Pages\TreePage;

class BlockIndexTree extends TreePage
{
    protected static string $resource = BlockIndexResource::class;

    protected static int $maxDepth = 10;

    /** ID корневого узла, чьи ветки показываем. */
    public ?int $rootId = null;

    /** ID родителя для создаваемого через «+» узла. */
    public ?int $preselectedParentId = null;

    public function mount(int $root): void
    {
        abort_unless(
            BlockIndex::where('id', $root)->where('parent_id', -1)->exists(),
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

        return BlockIndex::query()
            ->withCount('blocks')
            ->with(['blocks.translations'])
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

        // Проходим по уровням дерева, пока находим новых детей
        // withTrashed() гарантирует, что мы найдем детей, даже если родитель удален
        while (!empty($parentIds)) {
            $parentIds = BlockIndex::withTrashed()
                ->whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->toArray();

            $ids = array_merge($ids, $parentIds);
        }

        return $ids;
    }

    protected function getTreeToolbarActions(): array
    {
        return [];
    }

    protected function getTreeActions(): array
    {
        $actions = parent::getTreeActions();

        // Подменяем авторизацию на безопасную для всех действий
        foreach ($actions as $action) {
            if (in_array($action->getName(), ['edit', 'delete'])) {
                $action->authorize(fn (?Model $record = null) => $record !== null);
            }
        }

        // Добавляем ваше кастомное действие
        array_unshift($actions, AddChildAction::make());

        return $actions;
    }

    protected function hasDeleteAction(): bool
    {
        return true;
    }

    protected function hasEditAction(): bool
    {
        return true;
    }

    protected function hasViewAction(): bool
    {
        return false;
    }

    #[On('openCreateModal')]
    public function openCreateModal(int $parentId): void
    {
        $this->preselectedParentId = $parentId;

        // Передаем parent_id в аргументы действия
        $this->mountAction('create', ['parent_id' => $parentId]);
    }

    /**
     * Форма узла: переводы + сортировка блоков.
     */
    protected function getFormSchema(): array
    {
        return [
            Repeater::make('translations')
                ->relationship('translations')
                ->schema([
                    Select::make('locale')
                        ->options([
                            'en' => 'English',
                            'ru' => 'Русский',
                        ])
                        ->default(fn () => app()->getLocale())
                        ->required(),
                    TextInput::make('title')
                        ->label('Название')
                        ->required(),
                    Textarea::make('description')
                        ->label('Описание')
                        ->nullable(),
                ])
                ->columns(3)
                ->defaultItems(1)
                ->addActionLabel('Добавить перевод'),

            // === СОРТИРОВКА БЛОКОВ (через pivot-модель) ===
            Repeater::make('blockPivots')
                ->relationship('blockPivots')
                ->label('Порядок блоков в узле')
                ->orderColumn('order')
                ->schema([
                    Placeholder::make('block_info')
                        ->hiddenLabel()
                        ->content(fn (BlockBlockIndex $record) => $record->block
                            ? "ID: {$record->block->id} — " . ($record->block->title ?? 'Без названия')
                            : '—'),
                ])
                ->reorderable()
                ->addable(false)
                ->deletable(false)
                ->visible(fn (?BlockIndex $record) => $record && $record->blockPivots()->exists()),
        ];
    }

    protected function configureCreateAction(CreateAction $action): CreateAction
    {
        return parent::configureCreateAction($action)
            ->mutateFormDataUsing(function (array $data, array $arguments): array {
                // Берем parent_id из аргументов, если он есть
                $data['parent_id'] = $arguments['parent_id'] ?? $this->rootId ?? -1;
                return $data;
            });
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['parent_id'] = $this->preselectedParentId
            ?? session()->pull('block_index_parent_id')
            ?? $this->rootId;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->preselectedParentId = null;
    }

    public function getTitle(): string
    {
        $root = BlockIndex::find($this->rootId);
        return $root?->title ?? 'Иерархия блоков';
    }

    /**
     * Подпись узла в дереве.
     */
    public function getTreeRecordTitle(?Model $record = null): string
    {
        if (! $record) {
            return '';
        }

        $title = $record->title ?? 'Без названия';

        $blocks = $record->relationLoaded('blocks')
            ? $record->blocks
            : $record->blocks()->get();

        $count = $blocks->count();

        if ($count === 0) {
            return "[{$record->id}] {$title}";
        }

        $blockTitles = $blocks
            ->map(fn ($b) => $b->title ?: '—')
            ->filter()
            ->take(3)
            ->implode(', ');

        $suffix = $count > 3 ? " +" . ($count - 3) . " ещё" : "";

        return "[{$record->id}] {$title}  ·  📦 {$count}" . ($blockTitles ? ": {$blockTitles}{$suffix}" : "");
    }

    // ==================== ФИКС ДЕЙСТВИЙ ====================

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
