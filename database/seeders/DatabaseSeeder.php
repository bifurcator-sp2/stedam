<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Note: BlockTypeSeeder удалён вместе с таблицами block_types
     * и block_type_translations (миграция 2026_10_21_103040).
     * Блоки теперь используют строковый code.
     */
    public function run(): void
    {
        $this->call([
            UsersSeeder::class,
            CountrySeeder::class,
            RolesSeeder::class,
            RussianSchoolCurriculumSeeder::class,
            RussianHigherEducationCurriculumSeeder::class,

        ]);
    }
}
