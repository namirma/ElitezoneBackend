<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * تایم‌لاین الیت یار — دیتای تست
 *
 * php artisan db:seed --class=EliteYARTimelineTestSeeder
 *
 * یک کاربر الیت یار + یک سفارش با وضعیت weighbridge_done می‌سازد.
 * با ورود به عنوان الیت یار (09500000005 / password123) در داشبورد
 * Stage 2 «حق مشاوره منظور شد» به عنوان current نمایش داده می‌شود.
 *
 * نتیجه تایم‌لاین:
 *   مرحله ۱  کارفرما خرید کرد         → completed  (فیروزه‌ای)
 *   مرحله ۲  حق مشاوره منظور شد       → current    (آبی)
 *   مرحله ۳  فاکتور خدمات ثبت شد      → inactive   (خاکستری)
 *   مرحله ۴  حق مشاوره در دسترس است   → inactive   (خاکستری)
 */
class EliteYARTimelineTestSeeder extends Seeder
{
    private const ELITE_YAR_MOBILE = '09500000005';

    private const ELITE_YAR_NC = '5555555555';

    private const PASSWORD = 'password123';

    private const ORDER_NUMBER = 'ORD-CON-TEST-001';

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  EliteYARTimelineTestSeeder');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // ─── 1. کاربر الیت یار ──────────────────────────────────────────
        $this->command->info('▸ کاربر الیت یار...');
        $eliteYarType = UserType::firstOrCreate(
            ['slug' => 'elite_yar'],
            ['slug' => 'elite_yar', 'name' => 'الیت یار', 'icon' => 'star']
        );

        $eliteYAR = User::firstOrCreate(
            [User::MOBILE => self::ELITE_YAR_MOBILE],
            [
                User::MOBILE => self::ELITE_YAR_MOBILE,
                User::NATIONAL_CODE => self::ELITE_YAR_NC,
                User::BIRTH_DATE => '13700101',
                User::PASSWORD => Hash::make(self::PASSWORD),
                User::TYPE_ID => $eliteYarType->id,
            ]
        );

        UserProfile::firstOrCreate(
            ['user_id' => $eliteYAR->id],
            [
                'user_id' => $eliteYAR->id,
                'name' => 'سارا',
                'family' => 'الیت یارزاده',
                'national_code' => self::ELITE_YAR_NC,
            ]
        );

        Wallet::firstOrCreate(
            [Wallet::USER_ID => $eliteYAR->id],
            [
                Wallet::USER_ID => $eliteYAR->id,
                Wallet::BALANCE => 0,
                Wallet::BALANCE_HOLD => 0,
            ]
        );

        // ─── 2. پیدا کردن کارفرما و سوپلایر از DevSeeder ────────────────
        $employer = User::where(User::MOBILE, '09200000002')->first();
        $supplier = User::where(User::MOBILE, '09300000003')->first();

        if (! $employer || ! $supplier) {
            $this->command->error('کارفرما یا سوپلایر پیدا نشد — ابتدا DevSeeder را اجرا کنید.');

            return;
        }

        // ─── 3. سفارش با وضعیت weighbridge_done ─────────────────────────
        // این وضعیت باعث می‌شود Stage 2 تایم‌لاین الیت یار «current» شود.
        $this->command->info('▸ سفارش تستی...');

        $order = Order::firstOrCreate(
            [Order::ORDER_NUMBER => self::ORDER_NUMBER],
            [
                Order::PLACER_USER_ID => $employer->id,
                Order::BENEFICIARY_USER_ID => $eliteYAR->id,
                Order::ORDER_NUMBER => self::ORDER_NUMBER,
                Order::STATUS => Order::STATUS_SHIPPED,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::FINAL_AMOUNT => 1_200_000_000,
                Order::OFFICIAL_PRICE => 1_200_000_000,
                Order::WEIGHT_BEFORE_SCALE => 20_000,
                Order::SHIPPING_COST => 0,
                Order::VAT_RATE => 10,
                Order::PAID_AT => now()->subDays(3),
            ]
        );

        // ─── 4. آیتم سفارش ───────────────────────────────────────────────
        $variant = ProductVariant::where('sku', 'RBR-A2-12-SH')->first();

        if ($variant && $supplier) {
            OrderItem::firstOrCreate(
                [
                    OrderItem::ORDER_ID => $order->id,
                    OrderItem::VARIANT_SKU => $variant->sku,
                ],
                [
                    OrderItem::ORDER_ID => $order->id,
                    OrderItem::PRODUCT_VARIANT_ID => $variant->id,
                    OrderItem::SUPPLIER_USER_ID => $supplier->id,
                    OrderItem::PRODUCT_NAME => $variant->name ?? 'میلگرد آجدار',
                    OrderItem::VARIANT_SKU => $variant->sku,
                    OrderItem::QUANTITY_DETAILS => ['5.5' => 10000],
                    OrderItem::QUANTITY => 20_000,
                    OrderItem::WEIGHT => 20_000,
                    OrderItem::UNIT_NAME => 'کیلوگرم',
                    OrderItem::UNIT_PRICE => 60_000,
                    OrderItem::TOTAL_PRICE => 1_200_000_000,
                ]
            );
        }

        // ─── نتیجه ───────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  ✅  آماده است');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->table(
            ['نقش', 'موبایل', 'رمز', 'ID'],
            [
                ['الیت یار', self::ELITE_YAR_MOBILE, self::PASSWORD, $eliteYAR->id],
            ]
        );
        $this->command->info('  📦  سفارش تست: '.self::ORDER_NUMBER." (ID: {$order->id})");
        $this->command->info('');
        $this->command->info('  ▶  برای تست:');
        $this->command->info('     ۱. با موبایل 09500000005 / رمز password123 وارد شوید');
        $this->command->info('     ۲. داشبورد پروفایل را باز کنید');
        $this->command->info('     ۳. تایم‌لاین الیت یار نمایش داده می‌شود:');
        $this->command->info('        • مرحله ۱ (کارفرما خرید کرد)       → completed  ✅ فیروزه‌ای');
        $this->command->info('        • مرحله ۲ (حق مشاوره منظور شد)     → current    🔵 آبی + دو دکمه');
        $this->command->info('        • مرحله ۳ (فاکتور خدمات ثبت شد)    → inactive   ⬜ خاکستری');
        $this->command->info('        • مرحله ۴ (حق مشاوره در دسترس است) → inactive   ⬜ خاکستری');
        $this->command->info('');
        $this->command->info('  💡  دکمه‌های Stage 2:');
        $this->command->info('     • «مشاهده کیف پول»  → /profile/finance');
        $this->command->info("     • «فاکتور خدمات خود را ثبت کنید»  → /api/profile/orders/{$order->id}/elite-yar-moadian-invoice");
        $this->command->info('');
    }
}
