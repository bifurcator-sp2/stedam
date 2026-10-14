<?php

namespace App\Filament\Resources\Taxonomies\Pages;

use App\Filament\Resources\Taxonomies\TaxonomyResource;
use App\Filament\Tree\Actions\AddChildAction;
use App\Models\Taxonomy;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use SolutionForest\FilamentTree\Resources\Pages\TreePage;

class TaxonomyTree extends TreePage
{
    protected static string $resource = TaxonomyResource::class;

    protected static int $maxDepth = 10;

    /** ID корневого узла, чьи ветки показываем. */
    public ?int $rootId = null;

    /** ID родителя для создаваемого через «+» узла. */
    public ?int $preselectedParentId = null;

    public function mount(int $root): void
    {
        abort_unless(
            Taxonomy::where('id', $root)->where('parent_id', -1)->exists(),
            404
        );

        $this->rootId = $root;
    }

    /**
     * Дерево показывает только выбранный корень и его прямых потомков.
     * Для глубоких деревьев замените на рекурсивный сбор ID.
     */
    protected function getTreeQuery(): Builder
    {
        return Taxonomy::query()
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

    /**
     * «+» на узле — открыть создание с parent_id = id узла.
     */
    #[On('openCreateModal')]
    public function openCreateModal(int $parentId): void
    {
        $this->preselectedParentId = $parentId;
        $this->mountAction('create');
    }

    /**
     * Форма без поля «Родитель» — parent_id ставится программно.
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
        ];
    }

    protected function configureCreateAction(CreateAction $action): CreateAction
    {
        return parent::configureCreateAction($action)
            ->mutateFormDataUsing(function (array $data, array $arguments): array {
                // $arguments['parent_id'] приходит из AddChildAction::mountAction('create', ['parent_id' => ...])
                $data['parent_id'] = $arguments['parent_id'] ?? $this->rootId ?? -1;

                return $data;
            });
    }


    /**
     * При создании ставим parent_id:
     * — если через «+», то preselectedParentId;
     * — если через «Создать» в хедере, то сам корень (rootId).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['parent_id'] = $this->preselectedParentId ?? $this->rootId;
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->preselectedParentId = null;
    }

    public function getTitle(): string
    {
        $root = Taxonomy::find($this->rootId);

        return $root?->title ?? 'Дерево таксономии';
    }

    public function getTreeRecordTitle(?\Illuminate\Database\Eloquent\Model $record = null): string
    {
        if (! $record) {
            return '';
        }

        $title = $record->title ?? 'Без названия';

        return "[{$record->id}] {$title}";
    }


}
