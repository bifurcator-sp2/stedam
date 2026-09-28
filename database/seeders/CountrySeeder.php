<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['iso2' => 'RU', 'iso3' => 'RUS', 'phone_code' => '+7',  'ru' => 'Россия',         'en' => 'Russia'],
            ['iso2' => 'US', 'iso3' => 'USA', 'phone_code' => '+1',  'ru' => 'США',            'en' => 'United States'],
            ['iso2' => 'GB', 'iso3' => 'GBR', 'phone_code' => '+44', 'ru' => 'Великобритания', 'en' => 'United Kingdom'],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'phone_code' => '+49', 'ru' => 'Германия',       'en' => 'Germany'],
            ['iso2' => 'FR', 'iso3' => 'FRA', 'phone_code' => '+33', 'ru' => 'Франция',        'en' => 'France'],
            ['iso2' => 'IT', 'iso3' => 'ITA', 'phone_code' => '+39', 'ru' => 'Италия',         'en' => 'Italy'],
            ['iso2' => 'ES', 'iso3' => 'ESP', 'phone_code' => '+34', 'ru' => 'Испания',        'en' => 'Spain'],
            ['iso2' => 'CN', 'iso3' => 'CHN', 'phone_code' => '+86', 'ru' => 'Китай',          'en' => 'China'],
            ['iso2' => 'JP', 'iso3' => 'JPN', 'phone_code' => '+81', 'ru' => 'Япония',         'en' => 'Japan'],
            ['iso2' => 'KZ', 'iso3' => 'KAZ', 'phone_code' => '+7',  'ru' => 'Казахстан',      'en' => 'Kazakhstan'],
            ['iso2' => 'BY', 'iso3' => 'BLR', 'phone_code' => '+375', 'ru' => 'Беларусь',      'en' => 'Belarus'],
            ['iso2' => 'UA', 'iso3' => 'UKR', 'phone_code' => '+380', 'ru' => 'Украина',       'en' => 'Ukraine'],
            ['iso2' => 'TR', 'iso3' => 'TUR', 'phone_code' => '+90', 'ru' => 'Турция',         'en' => 'Turkey'],
            ['iso2' => 'IN', 'iso3' => 'IND', 'phone_code' => '+91', 'ru' => 'Индия',          'en' => 'India'],
            ['iso2' => 'BR', 'iso3' => 'BRA', 'phone_code' => '+55', 'ru' => 'Бразилия',       'en' => 'Brazil'],
        ];

        foreach ($countries as $data) {
            $country = Country::updateOrCreate(
                ['iso2' => $data['iso2']],
                [
                    'iso3' => $data['iso3'],
                    'phone_code' => $data['phone_code'],
                    'is_active' => true,
                ]
            );

            $country->translations()->updateOrCreate(
                ['locale' => 'ru'],
                ['name' => $data['ru']]
            );

            $country->translations()->updateOrCreate(
                ['locale' => 'en'],
                ['name' => $data['en']]
            );
        }
    }
}
