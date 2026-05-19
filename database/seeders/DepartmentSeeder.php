<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('departments')->insert(
            [
                [
                    'id' => 1,
                    'name' => 'بخش مالی',
                    'slug' => 'accountant',
                ],
                [
                    'id' => 2,
                    'name' => 'مدیریت',
                    'slug' => 'administration',
                ],
                [
                    'id' => 3,
                    'name' => 'وبسایت',
                    'slug' => 'website',
                ],
            ]
        );
    }
}
