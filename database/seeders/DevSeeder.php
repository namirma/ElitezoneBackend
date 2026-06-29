<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * DevSeeder — یک بار اجرا کنید تا دیتابیس توسعه پر شود.
 *
 * php artisan db:seed --class=DevSeeder
 *
 * ایجاد می‌کند:
 *   • نقش‌ها و انواع کاربری
 *   • محصولات، کارخانه‌ها، فیلترها (از سیدرهای موجود)
 *   • کاربر سیستم (سازه) + کیف پول
 *   • کاربر ادمین      + کیف پول
 *   • کاربر کارفرما   + کیف پول + آدرس
 *   • کاربر سوپلایر   + کیف پول
 *   • کاربر مهندس     + کیف پول
 *   • ۳ سفارش نمونه (pending / reserved / processing)
 */
class DevSeeder extends Seeder
{
    // ─── اطلاعات ورود ثابت ─────────────────────────────────────────────────
    // رمز ورود همه کاربران: password123
    private const PASSWORD = 'password123';

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  EliteZone DevSeeder');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // ── ۱. نقش‌ها و انواع کاربری ──────────────────────────────────────
        $this->command->info('▸ نقش‌ها و انواع کاربری...');

        // user_types — شامل icon که در UserTypeSeeder موجود نیست
        $userTypes = [
            ['slug' => 'customer',  'name' => 'مشتری',     'icon' => 'user'],
            ['slug' => 'engineer',  'name' => 'مهندس',     'icon' => 'hard-hat'],
            ['slug' => 'contractor','name' => 'پیمانکار',  'icon' => 'briefcase'],
            ['slug' => 'supplier',  'name' => 'سوپلایر',   'icon' => 'factory'],
            ['slug' => 'employer',  'name' => 'کارفرما',   'icon' => 'building'],
            ['slug' => 'expert',    'name' => 'مشاور فنی', 'icon' => 'star'],
        ];
        foreach ($userTypes as $t) {
            UserType::updateOrCreate(['slug' => $t['slug']], $t);
        }

        // roles (Laratrust)
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

        // ── ۲. محصولات / کارخانه / فیلترها ───────────────────────────────
        $this->command->info('▸ محصولات، کارخانه‌ها، پارامترها...');
        $this->call(SupplierActivityCategorySeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(PurchaseParameterSeeder::class);
        $this->call(DepartmentSeeder::class);

        // ── ۳. کاربر سیستم (Saze) ─────────────────────────────────────────
        $this->command->info('▸ کاربر سیستم (Saze)...');
        $systemUser = $this->createUser(
            mobile      : '09000000001',
            nationalCode: '0000000001',
            name        : 'سیستم',
            family      : 'سازه',
            balance     : 0,
        );
        $this->command->warn(
            "  ⚠  FINANCIAL_SYSTEM_USER_ID={$systemUser->id}  ← این را در .env تنظیم کنید"
        );

        // ── ۴. ادمین ──────────────────────────────────────────────────────
        $this->command->info('▸ ادمین...');
        $admin = $this->createUser(
            mobile      : '09100000001',
            nationalCode: '1111111111',
            name        : 'علی',
            family      : 'ادمین‌زاده',
            balance     : 500_000_000,
        );
        $this->grantRole($admin, 'admin');

        // ── ۵. کارفرما ─────────────────────────────────────────────────────
        $this->command->info('▸ کارفرما...');
        $employer = $this->createUser(
            mobile      : '09200000002',
            nationalCode: '2222222222',
            name        : 'محمد',
            family      : 'کارفرمایی',
            balance     : 5_000_000_000,   // ۵۰۰ میلیون تومان
            typeSug     : 'employer',
        );
        $this->grantRole($employer, 'employer');

        // آدرس برای کارفرما
        Address::firstOrCreate(
            [Address::USER_ID => $employer->id, Address::POSTAL_ADDRESS => 'تهران، خیابان ولیعصر، پلاک ۱۲'],
            [
                Address::USER_ID         => $employer->id,
                Address::TITLE           => 'دفتر مرکزی',
                Address::PROVINCE_ID     => null,   // province table ممکن است خالی باشد
                Address::CITY_ID         => null,
                Address::POSTAL_ADDRESS  => 'تهران، خیابان ولیعصر، پلاک ۱۲',
                Address::BUILDING_NUMBER => '۱۲',
                Address::UNIT            => '۳',
                Address::POSTAL_CODE     => '1234567890',
                Address::RECEIVER_NAME   => 'محمد کارفرمایی',
                Address::RECEIVER_MOBILE => '09200000002',
                Address::IS_DEFAULT      => true,
            ]
        );

        // ── ۶. سوپلایر ─────────────────────────────────────────────────────
        $this->command->info('▸ سوپلایر...');
        $supplier = $this->createUser(
            mobile      : '09300000003',
            nationalCode: '3333333333',
            name        : 'حسین',
            family      : 'سوپلایری',
            balance     : 200_000_000,
            typeSug     : 'supplier',
        );
        $this->grantRole($supplier, 'supplier');

        // ── ۷. مهندس (expert) ─────────────────────────────────────────────
        $this->command->info('▸ مهندس (expert)...');
        $engineer = $this->createUser(
            mobile      : '09400000004',
            nationalCode: '4444444444',
            name        : 'رضا',
            family      : 'مهندسی',
            balance     : 100_000_000,
            typeSug     : 'engineer',
        );
        $this->grantRole($engineer, 'engineer');

        // ── ۸. سفارش‌های نمونه ─────────────────────────────────────────────
        $this->command->info('▸ سفارش‌های نمونه...');
        $variant = ProductVariant::where('sku', 'RBR-A2-12-SH')->first();

        // ۸-الف: سفارش pending
        $this->makeOrder($employer->id, $supplier->id, $variant, Order::STATUS_PENDING, Order::PAYMENT_PENDING, 800_000_000, 'ORD-DEV-001');

        // ۸-ب: سفارش reserved (بیعانه پرداخت شده)
        $reserved = $this->makeOrder($employer->id, $supplier->id, $variant, Order::STATUS_RESERVED, Order::PAYMENT_PAID, 2_500_000_000, 'ORD-DEV-002');
        $reserved->update([
            Order::PAYMENT_TO_RESERVE      => 1_950_000_000,
            Order::RESERVATION_EXPIRES_AT  => now()->addHours(48),
        ]);

        // ۸-ج: سفارش processing (پرداخت کامل)
        $processed = $this->makeOrder($employer->id, $supplier->id, $variant, Order::STATUS_PROCESSING, Order::PAYMENT_PAID, 900_000_000, 'ORD-DEV-003');
        $processed->update([
            'paid_at' => now()->subHours(2),
        ]);

        // ─── خروجی نهایی ──────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  ✅  آماده است');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->table(
            ['نقش', 'موبایل', 'رمز', 'کیف پول (تومان)', 'ID'],
            [
                ['سیستم (Saze)', '09000000001', self::PASSWORD, '—',        $systemUser->id],
                ['ادمین',        '09100000001', self::PASSWORD, '۵۰,۰۰۰,۰۰۰', $admin->id],
                ['کارفرما',      '09200000002', self::PASSWORD, '۵۰۰,۰۰۰,۰۰۰', $employer->id],
                ['سوپلایر',      '09300000003', self::PASSWORD, '۲۰,۰۰۰,۰۰۰', $supplier->id],
                ['مهندس',        '09400000004', self::PASSWORD, '۱۰,۰۰۰,۰۰۰', $engineer->id],
            ]
        );
        $this->command->info('');
        $this->command->warn("  ⚠  در .env تنظیم کنید:  FINANCIAL_SYSTEM_USER_ID={$systemUser->id}");
        $this->command->info('  📦  سفارش‌های نمونه: ORD-DEV-001 (pending) | ORD-DEV-002 (reserved) | ORD-DEV-003 (processing)');
        $this->command->info('');
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /** نقش را فقط اگر قبلاً داده نشده اضافه می‌کند — idempotent */
    private function grantRole(User $user, string $roleName): void
    {
        if (! $user->hasRole($roleName)) {
            $user->addRole($roleName);
        }
    }

    private function createUser(
        string $mobile,
        string $nationalCode,
        string $name,
        string $family,
        int    $balance,
        string $typeSug = 'customer',
    ): User {
        $typeId = UserType::firstOrCreate(
            ['slug' => $typeSug],
            ['name' => $typeSug, 'slug' => $typeSug]
        )->id;

        $user = User::firstOrCreate(
            [User::MOBILE => $mobile],
            [
                User::MOBILE        => $mobile,
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE    => '13700101',
                User::PASSWORD      => Hash::make(self::PASSWORD),
                User::TYPE_ID       => $typeId,
            ]
        );

        // پروفایل
        UserProfile::firstOrCreate(
            [UserProfile::USER_ID => $user->id],
            [
                UserProfile::USER_ID      => $user->id,
                UserProfile::NAME         => $name,
                UserProfile::FAMILY       => $family,
                UserProfile::NATIONAL_CODE => $nationalCode,
            ]
        );

        // کیف پول
        Wallet::firstOrCreate(
            [Wallet::USER_ID => $user->id],
            [
                Wallet::USER_ID      => $user->id,
                Wallet::BALANCE      => $balance,
                Wallet::BALANCE_HOLD => 0,
            ]
        );

        return $user;
    }

    private function makeOrder(
        int            $buyerId,
        int            $supplierId,
        ?ProductVariant $variant,
        string         $status,
        string         $paymentStatus,
        int            $amount,
        string         $orderNumber,
    ): Order {
        $order = Order::firstOrCreate(
            [Order::ORDER_NUMBER => $orderNumber],
            [
                Order::PLACER_USER_ID      => $buyerId,
                Order::ORDER_NUMBER        => $orderNumber,
                Order::STATUS              => $status,
                Order::PAYMENT_STATUS      => $paymentStatus,
                Order::FINAL_AMOUNT        => $amount,
                Order::OFFICIAL_PRICE      => $amount,
                Order::WEIGHT_BEFORE_SCALE => 20_000,   // ۲۰ تن
                Order::SHIPPING_COST       => 0,
                Order::VAT_RATE            => 10,
            ]
        );

        // آیتم سفارش
        if ($variant) {
            OrderItem::firstOrCreate(
                [OrderItem::ORDER_ID => $order->id, OrderItem::VARIANT_SKU => $variant->sku],
                [
                    OrderItem::ORDER_ID          => $order->id,
                    OrderItem::PRODUCT_VARIANT_ID => $variant->id,
                    OrderItem::SUPPLIER_USER_ID  => $supplierId,
                    OrderItem::PRODUCT_NAME      => $variant->name ?? 'میلگرد آجدار',
                    OrderItem::VARIANT_SKU       => $variant->sku,
                    OrderItem::QUANTITY_DETAILS  => ['5.5' => 10000],
                    OrderItem::QUANTITY          => 20_000,
                    OrderItem::WEIGHT            => 20_000,
                    OrderItem::UNIT_NAME         => 'کیلوگرم',
                    OrderItem::UNIT_PRICE        => (int) ($amount / 20_000),
                    OrderItem::TOTAL_PRICE       => $amount,
                ]
            );
        }

        return $order;
    }
}
