<?php

namespace App\Filament\Resources\Taxonomies\Tables;

use App\Filament\Resources\Taxonomies\TaxonomyResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaxonomiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('parent_id', -1))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Название')
                    ->getStateUsing(fn ($record) => $record->title ?? '—')
                    ->searchable(false)
                    ->weight('bold'),

                TextColumn::make('children_count')
                    ->label('Веток')
                    ->counts('children')
                    ->sortable(),

                TextColumn::make('order')
                    ->label('Порядок')
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label('Удалено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('order')
            ->recordUrl(fn ($record) => TaxonomyResource::getUrl('tree', ['root' => $record->id]))
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
