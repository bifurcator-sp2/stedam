<?php

namespace App\Filament\Resources\UserData;

use App\Filament\Resources\UserData\Pages\CreateUserData;
use App\Filament\Resources\UserData\Pages\EditUserData;
use App\Filament\Resources\UserData\Pages\ListUserData;
use App\Filament\Resources\UserData\Schemas\UserDataForm;
use App\Filament\Resources\UserData\Tables\UserDataTable;
use App\Models\UserData;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserDataResource extends Resource
{
    protected static ?string $model = UserData::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function form(Schema $schema): Schema
    {
        return UserDataForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserDataTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserData::route('/'),
            'create' => CreateUserData::route('/create'),
            'edit' => EditUserData::route('/{record}/edit'),
        ];
    }


}
