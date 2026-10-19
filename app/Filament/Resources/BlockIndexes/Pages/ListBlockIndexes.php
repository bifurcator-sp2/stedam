<?php

namespace App\Filament\Resources\BlockIndexes\Pages;

use App\Filament\Resources\BlockIndexes\BlockIndexResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlockIndexes extends ListRecords
{
    protected static string $resource = BlockIndexResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать корень')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['parent_id'] = -1;
                    return $data;
                }),
        ];
    }
}
