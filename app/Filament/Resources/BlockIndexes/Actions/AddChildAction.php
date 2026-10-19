<?php

namespace App\Filament\Resources\BlockIndexes\Actions;

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
                // Сохраняем ID родителя в сессию (ключ совпадает с booted() в модели BlockIndex)
                session(['block_index_parent_id' => $record->getKey()]);

                // Отправляем событие в Livewire для открытия модального окна
                $livewire->dispatch('openCreateModal', parentId: $record->getKey());
            });
    }
}
