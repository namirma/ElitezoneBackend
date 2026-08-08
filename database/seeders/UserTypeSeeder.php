<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\UserType;
use Illuminate\Database\Seeder;

class UserTypeSeeder extends Seeder
{
    /**
     * Idempotent — can be run multiple times safely.
     * Uses updateOrCreate / firstOrCreate so duplicate runs don't error.
     */
    public function run(): void
    {
        // user_types (icon column is required — no default in schema)
        $userTypes = [
            ['slug' => 'engineer',   'name' => 'مهندس',    'icon' => 'hard-hat'],
            ['slug' => 'contractor', 'name' => 'پیمانکار', 'icon' => 'briefcase'],
            ['slug' => 'supplier',   'name' => 'سوپلایر',  'icon' => 'factory'],
            ['slug' => 'employer',   'name' => 'کارفرما',  'icon' => 'building'],
            ['slug' => 'elite_yar',     'name' => 'الیت یار', 'icon' => 'star'],
        ];

        foreach ($userTypes as $t) {
            UserType::updateOrCreate(['slug' => $t['slug']], $t);
        }

        // Laratrust roles (admin + all user roles)
        $roles = [
            ['name' => 'admin',      'display_name' => 'مدیر'],
            ['name' => 'employer',   'display_name' => 'کارفرما'],
            ['name' => 'engineer',   'display_name' => 'مهندس'],
            ['name' => 'contractor', 'display_name' => 'پیمانکار'],
            ['name' => 'supplier',   'display_name' => 'سوپلایر'],
        ];

        foreach ($roles as $r) {
            Role::firstOrCreate(['name' => $r['name']], $r);
        }
    }
}
