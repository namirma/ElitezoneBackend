<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // اول از همه: حساب‌های کیف‌پولیِ پلتفرم (WSSG/WVAT). بدون این‌ها هیچ
        // پرداختی انجام نمی‌شود — `wallets.user_id` کلید خارجی دارد و شناسه‌ای
        // که `financial.system_user_id` می‌گوید باید واقعاً وجود داشته باشد.
        $this->call(PlatformWalletUserSeeder::class);

        $this->call(SupplierActivityCategorySeeder::class);
        $this->call(AgreementSeeder::class);
        $this->call(AdminPermissionSeeder::class);
        // \App\Models\UserResource::factory(10)->create();

        // \App\Models\UserResource::factory()->create([
        //     'name' => 'Test UserResource',
        //     'email' => 'test@example.com',
        // ]);
        // Badges must be seeded before ProductSeeder — its badge price-adjustment
        // block reads the existing Badge rows by code.
        $this->call(BadgeSeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(PurchaseParameterSeeder::class);
        // محتوایی که تا امروز در کدِ فرانت هاردکد بود (FAQ/مگامنو/فوتر) — idempotent
        $this->call(SiteContentSeeder::class);
        $this->call(PromoOverlaySeeder::class);
    }
}
