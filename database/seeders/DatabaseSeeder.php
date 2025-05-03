<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\PurchaseParameterGroup;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SupplierActivityCategorySeeder::class);
        // \App\Models\UserResource::factory(10)->create();

        // \App\Models\UserResource::factory()->create([
        //     'name' => 'Test UserResource',
        //     'email' => 'test@example.com',
        // ]);
        $this->call(ProductSeeder::class);
        $this->call(PurchaseParameterSeeder::class);
    }
}
