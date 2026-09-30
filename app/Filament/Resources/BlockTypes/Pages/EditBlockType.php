<?php

namespace App\Filament\Resources\BlockTypes\Pages;

use App\Filament\Resources\BlockTypes\BlockTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBlockType extends EditRecord
{
    protected static string $resource = BlockTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
