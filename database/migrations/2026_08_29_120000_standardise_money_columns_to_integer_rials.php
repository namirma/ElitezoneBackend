<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * همه‌ی ستون‌های **مبلغ** روی یک قرارداد: `decimal(20,0)` — ریالِ صحیح.
 *
 * ‏پیش از این سه قرارداد هم‌زمان زنده بود — `decimal(20,0)`, `decimal(15,2)`,
 * `decimal(20,2)` — و مرزِ بینشان دقیقاً روی مسیرِ پول می‌افتاد:
 * `orders.final_amount` دو رقم اعشار داشت ولی از `wallets.balance` که
 * `decimal(20,0)` است برداشت می‌شد و در `wallet_transactions.amount` (باز هم
 * صفر اعشار) ثبت می‌شد. هر مبلغی با اعشار، **سرِ نوشتن** گرد می‌شد و اسنپ‌شاتِ
 * سفارش با دفترِ کیف پول تا یک ریال فاصله می‌گرفت. چون هر طرف با خودش سازگار
 * می‌ماند، `financial:doctor` هم چیزی نمی‌دید.
 *
 * ‏این مهاجرت **مقداری را تغییر نمی‌دهد**: پیش از اجرا شمرده شد و هیچ ستونِ
 * مبلغی مقدارِ کسری ذخیره نداشت (تنها ستونِ کسریِ کلِ دیتابیس
 * `orders.owner_discount_rate` بود که درصد است، نه پول). این تغییر فقط اسکیما
 * را وادار می‌کند همان چیزی را بگوید که کد از قبل رعایت می‌کند.
 *
 * ⚠️ **چه چیزهایی عمداً تبدیل نشدند:**
 *   - `coupons.amount`, `offers.value`, `product_variants.discount_value` —
 *     دووجهی‌اند: درصد **یا** مبلغ ثابت. درصدِ `۱۰٫۵` مشروع است.
 *   - هر ستونِ `*_rate` / `*_percentage` — درصدند.
 *   - وزن‌ها و مقادیر (`decimal(*,3)`, `*_order_value`, `order_units.amount`) —
 *     پول نیستند و اعشارشان معنا دارد.
 *   - `addresses.latitude/longitude`.
 *
 * ‏`ALTER ... MODIFY` خام و نه `->change()`: پروژه `doctrine/dbal` ندارد و
 * افزودنِ یک وابستگی برای یک مهاجرت گران‌تر از نوشتنِ SQL است. nullability و
 * default از `information_schema` خوانده می‌شوند تا `MODIFY` آن‌ها را نیندازد.
 */
return new class extends Migration
{
    /**
     * ستون ← شکلِ قبلی. مقدارِ قبلی نگه داشته می‌شود تا `down()` واقعاً برگرداند،
     * نه اینکه همه را به یک شکلِ دلبخواه ببرد.
     *
     * @var array<string, array<string, string>>
     */
    private const MONEY_COLUMNS = [
        'orders' => [
            'subtotal' => 'decimal(15,2)',
            'discount_amount' => 'decimal(15,2)',
            'shipping_cost' => 'decimal(15,2)',
            'vat_amount' => 'decimal(15,2)',
            'final_amount' => 'decimal(15,2)',
            'partial_payment_amount' => 'decimal(15,2)',
            'expert_fee_amount' => 'decimal(15,2)',
            'engineer_fee_amount' => 'decimal(15,2)',
            'contractor_fee_amount' => 'decimal(15,2)',
            'buyer_refund_pending_amount' => 'decimal(15,2)',
            'coupon_discount_amount' => 'decimal(18,2)',
            'official_price' => 'decimal(18,2)',
            'payment_of_owner' => 'decimal(20,2)',
            'payment_to_supplier' => 'decimal(20,2)',
            'payment_to_elite_yar' => 'decimal(20,2)',
            'payment_to_reserve' => 'decimal(20,2)',
            'supplier_debt_amount' => 'decimal(20,2)',
            'supplier_vat_pending_amount' => 'decimal(20,2)',
            'overweight_pending_amount' => 'decimal(20,2)',
        ],
        'order_items' => [
            'unit_price' => 'decimal(15,2)',
            'total_price' => 'decimal(15,2)',
            'shipping_cost' => 'decimal(20,2)',
        ],
        'product_price_caches' => ['final_price' => 'decimal(15,2)'],
        'debt_entries' => ['amount' => 'decimal(20,2)'],
        'invoices' => ['amount' => 'decimal(20,2)'],
        'return_damages' => ['amount' => 'decimal(20,2)'],
        'shipments' => ['shipping_cost' => 'decimal(20,2)'],
        'shipping_rates' => [
            'flat_cost' => 'decimal(20,2)',
            'per_kg_cost' => 'decimal(20,2)',
            'per_count_cost' => 'decimal(20,2)',
        ],
        'abandoned_carts' => ['total_amount' => 'decimal(20,2)'],
        'users' => [
            'badge_purchase_amount' => 'decimal(20,2)',
            'debt_balance' => 'decimal(20,2)',
            'credit_limit' => 'decimal(20,2)',
        ],
    ];

    public function up(): void
    {
        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $this->retype($table, $column, 'decimal(20,0)');
            }
        }
    }

    public function down(): void
    {
        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $original) {
                $this->retype($table, $column, $original);
            }
        }
    }

    /**
     * نوعِ ستون را عوض می‌کند و nullability/default را دست‌نخورده نگه می‌دارد.
     *
     * ‏`MODIFY` در MySQL کلِ تعریف را بازمی‌نویسد، پس هرچه ننویسی از دست می‌رود —
     * یک ستونِ `NOT NULL DEFAULT 0` بدونِ این کار به `NULL` بدونِ default تبدیل
     * می‌شد و اولین درجِ بعدی می‌شکست یا بی‌صدا `null` می‌نشاند.
     *
     * ستونی که روی این برنچ وجود ندارد بی‌سروصدا رد می‌شود — L10 و L13 مجموعه‌ی
     * یکسانی از جدول‌ها ندارند.
     */
    private function retype(string $table, string $column, string $type): void
    {
        $meta = DB::selectOne(
            'SELECT IS_NULLABLE n, COLUMN_DEFAULT d
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($meta === null) {
            return;
        }

        $nullable = $meta->n === 'YES';
        $nullability = $nullable ? 'NULL' : 'NOT NULL';

        // ⚠️ MySQL 8 برای ستونِ nullable با پیش‌فرضِ NULL، **رشته‌ی چهارحرفیِ
        // `"NULL"`** در `COLUMN_DEFAULT` می‌گذارد، نه PHP `null`. بدون این تبدیل
        // شرطِ `is_numeric` رد می‌شد و `DEFAULT 'NULL'` — یک رشته — روی ستونِ
        // decimal ساخته می‌شد: «Invalid default value».
        $raw = $meta->d;
        if ($raw !== null && strcasecmp((string) $raw, 'NULL') === 0) {
            $raw = null;
        }

        if ($raw === null) {
            $default = $nullable ? ' DEFAULT NULL' : '';
        } elseif (is_numeric($raw)) {
            // پیش‌فرضِ عددی را به دقتِ مقصد می‌بریم — `DEFAULT '0.00'` روی ستونِ
            // صفر-اعشار در حالتِ strict هشدار می‌دهد.
            $default = ' DEFAULT '.(str_ends_with($type, ',0)')
                ? (string) (int) round((float) $raw)
                : (string) $raw);
        } else {
            $default = ' DEFAULT '.DB::getPdo()->quote((string) $raw);
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} {$nullability}{$default}");
    }
};
