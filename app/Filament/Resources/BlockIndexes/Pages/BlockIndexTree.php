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
use Livewire\Attributes\On;
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

    protected function getTreeQuery(): Builder
    {
        return BlockIndex::query()
            ->withCount('blocks')
            ->with(['blocks.translations'])
            ->where(function (Builder $q) {
                $q->where('id', $this->rootId)
                    ->orWhere('parent_id', $this->rootId);
            });
    }

    protected function getTreeToolbarActions(): array
    {
        return [];
    }

    protected function getTreeActions(): array
    {
        $actions = parent::getTreeActions();
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
        $this->mountAction('create');
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
                ->orderColumn('order') // Сохраняет порядок в колонку order таблицы block_block_index
                ->schema([
                    Placeholder::make('block_info')
                        ->hiddenLabel()
                        ->content(fn (BlockBlockIndex $record) => $record->block
                            ? "ID: {$record->block->id} — " . ($record->block->title ?? 'Без названия')
                            : '—'),
                ])
                ->reorderable()     // Drag & Drop
                ->addable(false)    // Запрещаем добавлять
                ->deletable(false)  // Запрещаем удалять
                ->visible(fn (?BlockIndex $record) => $record && $record->blockPivots()->exists()),
        ];
    }

    protected function configureCreateAction(CreateAction $action): CreateAction
    {
        return parent::configureCreateAction($action)
            ->mutateFormDataUsing(function (array $data, array $arguments): array {
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
    public function getTreeRecordTitle(?\Illuminate\Database\Eloquent\Model $record = null): string
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
}
