<?php

namespace App\Filament\Resources\Taxonomies\Pages;

use App\Filament\Resources\Taxonomies\TaxonomyResource;
use App\Filament\Resources\Taxonomies\Widgets\CreateRootAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomies extends ListRecords
{
    protected static string $resource = TaxonomyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать корень')
                ->mutateFormDataUsing(function (array $data): array {
                    $data['parent_id'] = -1;
                    return $data;
                }),
        ];
    }
}
