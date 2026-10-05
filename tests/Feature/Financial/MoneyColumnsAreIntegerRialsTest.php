<?php

namespace Tests\Feature\Financial;

use App\Services\Financial\SettlementCalculatorService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پول در این سیستم **ریالِ صحیح** است — ریال زیرواحد ندارد.
 *
 * تا پیش از این سه قرارداد هم‌زمان زنده بود (`decimal(20,0)`, `decimal(15,2)`,
 * `decimal(20,2)`) و مرزشان دقیقاً روی مسیرِ پول می‌افتاد: `orders.final_amount`
 * دو رقم اعشار داشت ولی از `wallets.balance` که `decimal(20,0)` است برداشت
 * می‌شد. هر مبلغِ کسری سرِ نوشتن گرد می‌شد و اسنپ‌شاتِ سفارش با دفترِ کیف پول
 * فاصله می‌گرفت — و چون هر طرف با خودش سازگار می‌ماند، `financial:doctor` هم
 * چیزی نمی‌دید.
 *
 * این تست همان مرز را می‌بندد: هم در **مبدأ** (خروجیِ محاسبه‌گر) و هم در
 * **مقصد** (شکلِ ستون).
 */
class MoneyColumnsAreIntegerRialsTest extends TestCase
{
    /**
     * ستون‌هایی که مبلغ‌اند و باید `decimal(*,0)` باشند.
     *
     * @var array<string, list<string>>
     */
    private const MONEY = [
        'wallets' => ['balance', 'balance_hold'],
        'wallet_transactions' => ['amount'],
        // `official_price` و `supplier_price` **قیمتِ هر کیلو**اند نه مبلغ، و از
        // ۱۴۰۵/۰۷/۱۳ چهار رقمِ اعشار دارند: گردکردنِ هر کیلو (تا ۰٫۵ ریال) × وزن، مبلغِ
        // نمایش‌داده‌شده و مبلغِ کسرشده را تا ۰٫۵ ریال × وزن از هم دور می‌کرد — روی ۱۰ تن
        // ۵٬۰۰۰ ریال — و پاسخِ مدیریت به پرسشِ ۲ می‌خواهد این دو **دقیقاً** یکی باشند.
        'orders' => [
            'subtotal', 'discount_amount', 'shipping_cost', 'vat_amount', 'final_amount',
            'coupon_discount_amount', 'buyer_discount_amount', 'payment_of_owner',
            'payment_to_supplier', 'payment_to_elite_yar', 'payment_to_reserve',
            'supplier_debt_amount', 'overweight_pending_amount',
        ],
        'order_items' => ['unit_price', 'total_price', 'shipping_cost'],
        'cart_items' => ['price', 'final_price', 'vat_amount', 'total_with_vat'],
        'product_prices' => ['general_price', 'special_price', 'golden_figure'],
        'debt_entries' => ['amount'],
        'coupon_funding_legs' => ['amount'],
        'invoices' => ['amount'],
        'return_damages' => ['amount'],
        'shipments' => ['shipping_cost', 'rate_flat_cost', 'rate_unit_cost'],
        'shipping_rates' => ['flat_cost', 'per_kg_cost', 'per_count_cost'],
        'users' => ['badge_purchase_amount', 'debt_balance', 'credit_limit'],
    ];

    #[Test]
    public function every_money_column_stores_whole_rials(): void
    {
        $offenders = [];

        foreach (self::MONEY as $table => $columns) {
            foreach ($columns as $column) {
                $meta = DB::selectOne(
                    'SELECT COLUMN_TYPE t, NUMERIC_SCALE s FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );

                // ستونِ غایب هم خطاست: تغییرِ نام یا حذفِ یک ستونِ مبلغ نباید این
                // تست را بی‌صدا سبز نگه دارد.
                if ($meta === null) {
                    $offenders[] = "{$table}.{$column} does not exist";

                    continue;
                }

                if ((int) $meta->s !== 0) {
                    $offenders[] = "{$table}.{$column} is {$meta->t}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این ستون‌ها مبلغ‌اند ولی اعشار دارند (کیف پول `decimal(20,0)` است) یا دیگر وجود ندارند:\n  "
            .implode("\n  ", $offenders)
        );
    }

    #[Test]
    public function the_settlement_calculator_returns_whole_rials(): void
    {
        $calc = new SettlementCalculatorService;

        // اعدادی که عمداً کسر می‌سازند: وزنِ اعشاری × قیمتِ فرد، با هر چهار حالتِ
        // فرمول. پیش از اصلاح، `Money::toFloat()` پیش‌فرضِ دورقمی داشت و اینجا
        // چیزی مثل `…٫۸۹` برمی‌گشت که سفارش ذخیره‌اش می‌کرد و کیف پول گردش.
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$tc, $vat]) {
            $poo = $calc->calculatePOO(4_999_999, 27_777, 20_000.5, 0.37, 9.5, $tc, $vat);
            $pts = $calc->calculatePTS(4_999_999, 27_777, 20_000.5, 0.37, 0.225, 0.375, 9.5, $tc, $vat);

            $this->assertSame($poo, round($poo), "POO باید ریالِ صحیح باشد، شد {$poo}");
            $this->assertSame($pts, round($pts), "PTS باید ریالِ صحیح باشد، شد {$pts}");
        }
    }

    #[Test]
    public function vat_on_transport_is_a_whole_rial_too(): void
    {
        // این عدد مستقیم در `orders.vat_amount` می‌نشیند.
        $vat = (new SettlementCalculatorService)->vatOnTrans(4_999_999, 9.5, true);

        $this->assertSame($vat, round($vat), "مالیاتِ حمل باید ریالِ صحیح باشد، شد {$vat}");
    }
}
