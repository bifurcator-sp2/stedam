<?php

namespace App\Filament\Resources\UserData\Pages;

use App\Filament\Resources\UserData\UserDataResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserData extends ListRecords
{
    protected static string $resource = UserDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
