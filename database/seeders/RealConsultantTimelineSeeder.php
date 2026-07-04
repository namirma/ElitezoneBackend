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
 * RealConsultantTimelineSeeder — دیتای تایم‌لاین الیت یار برای حساب واقعی.
 *
 * php artisan db:seed --class=RealConsultantTimelineSeeder
 *
 * با شخصیت‌های واقع‌گرایانه (نام، کد ملی معتبر، موبایل، تاریخ تولد) دو سناریو می‌سازد:
 *
 *  A) کاربرِ واقعی «مشاور» سفارش است — یک کارفرما خرید کرده و کاربر را به‌عنوان
 *     مشاورِ فنی وارد کرده (placer = کارفرما، beneficiary = کاربر). نمایش: داشبورد.
 *       A1 processing                                → مرحله ۱
 *       A2 weighbridge_done                          → مرحله ۲
 *       A3 weighbridge_done + moadian_ref            → مرحله ۳
 *       A4 weighbridge_done + moadian_ref + released → مرحله ۴
 *
 *  B) کاربرِ واقعی خودش «خریدار» است و برای یک کارفرما خرید کرده
 *     (placer = کاربر، beneficiary = کارفرما). نمایش: تب «خرید برای دیگران».
 *       B1 weighbridge_done  | B2 shipped | B3 cancelled
 *
 * چون خودِ کاربر الیت یار است، مشاورِ همهٔ این سفارش‌ها خودِ اوست → «الیت یار: شما».
 *
 * ⚠️ حسابِ کاربرِ واقعی فقط خوانده می‌شود و هرگز تغییر نمی‌کند.
 */
class RealConsultantTimelineSeeder extends Seeder
{
    private const CONSULTANT_MOBILE = '09130603199'; // فقط برای پیدا کردن (read-only)

    private const PASSWORD = 'password123';

    private const VARIANT_SKU = 'RBR-A2-12-SH';

    private const AMOUNT = 1_500_000_000;

    private ?int $supplierId = null;

    private ?ProductVariant $variant = null;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  RealConsultantTimelineSeeder');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // ─── مشاور واقعی (READ-ONLY) ────────────────────────────────────
        $consultant = User::where(User::MOBILE, self::CONSULTANT_MOBILE)->first();
        if (! $consultant) {
            $this->command->error('  ✗ کاربر '.self::CONSULTANT_MOBILE.' یافت نشد.');

            return;
        }
        $this->command->info("  ✓ مشاور واقعی: ID={$consultant->id} (دست‌نخورده)");

        // ─── پاک‌سازی دیتای تستِ قبلی (سفارش‌ها + کاربرانِ جای‌گزینِ قدیمی) ─
        $this->cleanupPreviousTestData();

        // ─── شخصیت‌های واقع‌گرایانه ──────────────────────────────────────
        // دو کارفرما که کاربر را به‌عنوان مشاور معرفی کرده‌اند (سناریو A)
        $employerA = $this->makePersona('بهرام', 'رستگار', '09131045820', $this->nationalCode('128400637'), '13550312', 250_000_000);
        $employerB = $this->makePersona('کیوان', 'شفیعی', '09122847391', $this->nationalCode('006273841'), '13601125', 180_000_000);
        // کارفرمایی که کاربر برایش خرید کرده (سناریو B — گیرندهٔ سفارش)
        $client = $this->makePersona('امیرحسین', 'تقوی', '09139962475', $this->nationalCode('127995310'), '13680508', 90_000_000);

        // ─── سوپلایر و محصول ────────────────────────────────────────────
        $supplierType = UserType::where('slug', 'supplier')->first();
        $this->supplierId = $supplierType
            ? (User::where(User::TYPE_ID, $supplierType->id)->value('id') ?? $employerA->id)
            : $employerA->id;
        $this->variant = ProductVariant::where('sku', self::VARIANT_SKU)->first();

        // ─── سناریو A: کاربر مشاورِ سفارشِ کارفرماست (داشبورد) ──────────
        $this->command->info('▸ سناریو A — کارفرما خرید کرده، کاربر مشاورِ آن است:');
        $a1 = $this->makeOrder($employerA->id, $consultant->id, 'ORD-CONS-A1', Order::STATUS_PROCESSING);
        $a2 = $this->makeOrder($employerA->id, $consultant->id, 'ORD-CONS-A2', Order::STATUS_WEIGHBRIDGE_DONE);
        $a3 = $this->makeOrder($employerB->id, $consultant->id, 'ORD-CONS-A3', Order::STATUS_WEIGHBRIDGE_DONE, [
            Order::CONSULTANT_MOADIAN_REF => '1402-CON-A3-77451',
        ]);
        $a4 = $this->makeOrder($employerB->id, $consultant->id, 'ORD-CONS-A4', Order::STATUS_WEIGHBRIDGE_DONE, [
            Order::CONSULTANT_MOADIAN_REF => '1402-CON-A4-77452',
            Order::CONSULTANT_HOLD_RELEASED_AT => now()->subDay(),
        ]);

        // ─── سناریو B: کاربر برای کارفرما خرید کرده (تب خرید برای دیگران) ─
        $this->command->info('▸ سناریو B — کاربر برای کارفرمای دیگری خرید کرده:');
        $this->makeOrder($consultant->id, $client->id, 'ORD-CONS-B1', Order::STATUS_WEIGHBRIDGE_DONE);
        $this->makeOrder($consultant->id, $client->id, 'ORD-CONS-B2', Order::STATUS_SHIPPED);
        $this->makeOrder($consultant->id, $client->id, 'ORD-CONS-B3', Order::STATUS_CANCELLED);
        // تحویل‌شده: پورسانت آزاد شده → هر ۴ مرحلهٔ تایم‌لاین مشاور completed
        $this->makeOrder($consultant->id, $client->id, 'ORD-CONS-B4', Order::STATUS_DELIVERED, [
            Order::CONSULTANT_MOADIAN_REF => '1402-CON-B4-88120',
            Order::CONSULTANT_HOLD_RELEASED_AT => now()->subDay(),
        ]);

        // ─── خلاصه ───────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  ✅  ساخته شد — وضعیت تایم‌لاین مشاور (سناریو A):');
        foreach ([['A1', $a1], ['A2', $a2], ['A3', $a3], ['A4', $a4]] as [$tag, $o]) {
            $cp = $o->fresh()->consultant_progress;
            $current = collect($cp['steps'])->firstWhere('state', 'current');
            $label = $current ? "مرحله {$current['step']} ({$current['title']})" : 'تکمیل‌شده';
            $this->command->info("    {$tag} [{$o->order_number}] → {$label}");
        }
        $this->command->info('');
        $this->command->info('  شخصیت‌ها:');
        $this->command->info("    کارفرما A: بهرام رستگار   ({$employerA->mobile})");
        $this->command->info("    کارفرما B: کیوان شفیعی    ({$employerB->mobile})");
        $this->command->info("    گیرنده   : امیرحسین تقوی  ({$client->mobile})");
        $this->command->info('');
        $this->command->info('  ▶  با '.self::CONSULTANT_MOBILE.' (کد پیامکی) وارد شوید → داشبورد و تب سفارش‌ها.');
        $this->command->info('');
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /** پاک‌سازی سفارش‌های تستِ این seeder و کاربرانِ جای‌گزینِ قدیمی (placeholder). */
    private function cleanupPreviousTestData(): void
    {
        $oldOrderIds = Order::where(Order::ORDER_NUMBER, 'like', 'ORD-CONS-%')->pluck('id');
        if ($oldOrderIds->isNotEmpty()) {
            OrderItem::whereIn(OrderItem::ORDER_ID, $oldOrderIds)->delete();
            Order::whereIn('id', $oldOrderIds)->delete();
        }

        // موبایل‌های placeholder نسخهٔ قبلی این seeder
        $oldFakeMobiles = ['09120000111', '09120000222'];
        foreach (User::whereIn(User::MOBILE, $oldFakeMobiles)->get() as $fu) {
            Wallet::where(Wallet::USER_ID, $fu->id)->delete();
            UserProfile::where('user_id', $fu->id)->delete();
            $fu->delete();
        }
    }

    /** افزودن رقمِ کنترلِ کد ملی ایرانی به ۹ رقمِ پایه (الگوریتم رسمی). */
    private function nationalCode(string $base9): string
    {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $base9[$i]) * (10 - $i);
        }
        $r = $sum % 11;
        $check = $r < 2 ? $r : 11 - $r;

        return $base9.$check;
    }

    private function makePersona(string $name, string $family, string $mobile, string $nc, string $birth, int $balance): User
    {
        $type = UserType::firstOrCreate(['slug' => 'employer'], ['slug' => 'employer', 'name' => 'کارفرما', 'icon' => 'building']);

        $user = User::updateOrCreate(
            [User::MOBILE => $mobile],
            [
                User::NATIONAL_CODE => $nc,
                User::BIRTH_DATE => $birth,
                User::PASSWORD => Hash::make(self::PASSWORD),
                User::TYPE_ID => $type->id,
            ]
        );

        UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['name' => $name, 'family' => $family, 'national_code' => $nc]
        );

        Wallet::firstOrCreate(
            [Wallet::USER_ID => $user->id],
            [Wallet::BALANCE => $balance, Wallet::BALANCE_HOLD => 0]
        );

        return $user;
    }

    private function makeOrder(int $placerId, int $beneficiaryId, string $orderNumber, string $status, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            Order::PLACER_USER_ID => $placerId,
            Order::BENEFICIARY_USER_ID => $beneficiaryId,
            Order::ORDER_NUMBER => $orderNumber,
            Order::STATUS => $status,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => self::AMOUNT,
            Order::OFFICIAL_PRICE => self::AMOUNT,
            Order::WEIGHT_BEFORE_SCALE => 20_000,
            Order::SHIPPING_COST => 0,
            Order::VAT_RATE => 10,
            Order::PAID_AT => now()->subDays(2),
        ], $extra));

        if ($this->variant) {
            OrderItem::create([
                OrderItem::ORDER_ID => $order->id,
                OrderItem::PRODUCT_VARIANT_ID => $this->variant->id,
                OrderItem::SUPPLIER_USER_ID => $this->supplierId,
                OrderItem::PRODUCT_NAME => $this->variant->name ?? 'میلگرد آجدار',
                OrderItem::VARIANT_SKU => $this->variant->sku,
                OrderItem::QUANTITY_DETAILS => ['5.5' => 10000],
                OrderItem::QUANTITY => 20_000,
                OrderItem::WEIGHT => 20_000,
                OrderItem::UNIT_NAME => 'کیلوگرم',
                OrderItem::UNIT_PRICE => 75_000,
                OrderItem::TOTAL_PRICE => self::AMOUNT,
            ]);
        }

        return $order;
    }
}
