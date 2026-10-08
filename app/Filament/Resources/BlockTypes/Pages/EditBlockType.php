<?php

namespace App\Filament\Resources\BlockTypes\Pages;

use App\Blocks\Settings\SettingsNormalizer;
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


    // При открытии формы: структура → реестр, значения → из БД
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['default_settings'] = SettingsNormalizer::normalize(
            $data['code'] ?? null,
            $data['default_settings'] ?? [],
        );

        return $data;
    }

    // При сохранении: структура → реестр, значения → из формы
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_settings'] = SettingsNormalizer::normalize(
            $data['code'] ?? null,
            $data['default_settings'] ?? [],
        );
        return $data;
    }
}
