<?php

namespace Database\Seeders;

use App\Models\UserType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('user_types')->insert([
            [
                UserType::NAME => 'مهندس',
                UserType::SLUG => 'engineer'
            ],
            [
                UserType::NAME => 'پیمانکار',
                UserType::SLUG => 'contractor',
            ],
            [
                UserType::NAME => 'سوپلایر',
                UserType::SLUG => 'supplier',
            ],
            [
                UserType::NAME => 'کارفرما',
                UserType::SLUG => 'employer',
            ]
        ]);

        DB::table('roles')->insert([
            [
                'name' => 'engineer',
                'display_name' => 'مهندس'
            ],
            [
                'name' => 'contractor',
                'display_name' => 'پیمانکار',
            ],
            [
                'name' => 'supplier',
                'display_name' => 'سوپلایر',
            ],
            [
                'name' => 'employer',
                'display_name' => 'کارفرما',
            ]
        ]);
    }
}
