<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'admin', 'ru' => 'Администратор', 'en' => 'Administrator'],
            ['name' => 'student', 'ru' => 'Ученик', 'en' => 'Student'],
            ['name' => 'teacher', 'ru' => 'Учитель', 'en' => 'Teacher'],
            ['name' => 'parent', 'ru' => 'Родитель', 'en' => 'Parent'],
            ['name' => 'methodologist', 'ru' => 'Методист', 'en' => 'Methodologist'],
        ];

        foreach ($roles as $data) {
            $role = Role::firstOrCreate(
                ['name' => $data['name'], 'guard_name' => 'web']
            );

            $role->translations()->updateOrCreate(
                ['locale' => 'ru'],
                ['label' => $data['ru'], 'description' => '']
            );

            $role->translations()->updateOrCreate(
                ['locale' => 'en'],
                ['label' => $data['en'], 'description' => '']
            );
        }
    }
}
