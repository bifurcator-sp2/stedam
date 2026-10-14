<?php

namespace App\Filament\Tree\Actions;

use Filament\Actions\Action as BaseAction;
use SolutionForest\FilamentTree\Concern\Actions\TreeActionTrait;

class AddChildAction extends BaseAction
{
    use TreeActionTrait;

    public static function getDefaultName(): ?string
    {
        return 'addChild';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Добавить дочерний')
            ->icon('heroicon-o-plus')
            ->iconButton()
            ->color('success')
            ->tooltip('Добавить дочерний')
            ->action(function (array $arguments, $record, $livewire) {
                session(['taxonomy_parent_id' => $record->getKey()]);
                $livewire->dispatch('openCreateModal', parentId: $record->getKey());
            });
    }
}
