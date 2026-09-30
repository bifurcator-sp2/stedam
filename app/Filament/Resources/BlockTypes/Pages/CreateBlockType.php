<?php

namespace App\Filament\Resources\BlockTypes\Pages;

use App\Filament\Resources\BlockTypes\BlockTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlockType extends CreateRecord
{
    protected static string $resource = BlockTypeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
