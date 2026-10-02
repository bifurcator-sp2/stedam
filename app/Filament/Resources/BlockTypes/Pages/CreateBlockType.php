<?php

namespace App\Filament\Resources\BlockTypes\Pages;

use App\Blocks\Settings\SettingsSynchronizer;
use App\Filament\Resources\BlockTypes\BlockTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlockType extends CreateRecord
{
    protected static string $resource = BlockTypeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        // Если в форме default_settings пусто или частично —
        // forSave добавит всё отсутствующее из реестра.
        $data['default_settings'] = SettingsSynchronizer::forSave(
            $data['code'] ?? null,
            $data['default_settings'] ?? [],
        );

        return $data;
    }
}
