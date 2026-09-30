<?php

namespace App\Filament\Resources\BlockTypes\Pages;

use App\Filament\Resources\BlockTypes\BlockTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlockTypes extends ListRecords
{
    protected static string $resource = BlockTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
