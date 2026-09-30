<?php

namespace App\Filament\Resources\BlockTypes;

use App\Filament\Resources\BlockTypes\Pages\CreateBlockType;
use App\Filament\Resources\BlockTypes\Pages\EditBlockType;
use App\Filament\Resources\BlockTypes\Pages\ListBlockTypes;
use App\Filament\Resources\BlockTypes\Schemas\BlockTypeForm;
use App\Filament\Resources\BlockTypes\Tables\BlockTypesTable;
use App\Models\BlockType;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class BlockTypeResource extends Resource
{
    protected static ?string $model = BlockType::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $modelLabel = 'Тип блока';
    protected static ?string $pluralModelLabel = 'Типы блоков';
    protected static ?string $navigationLabel = 'Типы блоков';

    public static function form(Schema $schema): Schema
    {
        return BlockTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlockTypesTable::configure($table);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['translations', 'owner']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlockTypes::route('/'),
            'create' => CreateBlockType::route('/create'),
            'edit' => EditBlockType::route('/{record}/edit'),
        ];
    }
}
