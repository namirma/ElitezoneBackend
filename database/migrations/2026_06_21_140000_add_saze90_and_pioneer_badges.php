<?php

use App\Models\Badge;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Display badges beyond the gold/silver/bronze commission tiers:
        //   saze90  — member of the saze90 products (backend-detected, read-only)
        //   pioneer — granted to early members
        Badge::firstOrCreate(['code' => 'saze90'], ['name' => 'کاربر سازه ۹۰', 'icon' => 'badges/saze90.png']);
        Badge::firstOrCreate(['code' => 'pioneer'], ['name' => 'پیشگام', 'icon' => 'badges/pioneer.png']);
    }

    public function down(): void
    {
        Badge::whereIn('code', ['saze90', 'pioneer'])->delete();
    }
};
