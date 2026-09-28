<?php

namespace App\Filament\Resources\UserData\Pages;

use App\Filament\Resources\UserData\UserDataResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUserData extends EditRecord
{
    protected static string $resource = UserDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
