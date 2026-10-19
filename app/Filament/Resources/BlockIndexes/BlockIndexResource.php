<?php

namespace App\Filament\Resources\BlockIndexes;

use App\Filament\Resources\BlockIndexes\Pages\ListBlockIndexes;
use App\Filament\Resources\BlockIndexes\Pages\BlockIndexTree;
use App\Filament\Resources\BlockIndexes\Schemas\BlockIndexForm;
use App\Filament\Resources\BlockIndexes\Tables\BlockIndexesTable;
use App\Models\BlockIndex;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BlockIndexResource extends Resource
{
    protected static ?string $model = BlockIndex::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Иерархия блоков';

    protected static ?string $modelLabel = 'Узел блоков';

    protected static ?string $pluralModelLabel = 'Иерархия блоков';

    public static function form(Schema $schema): Schema
    {
        return BlockIndexForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlockIndexesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlockIndexes::route('/'),
            'tree' => BlockIndexTree::route('/{root}/tree'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
