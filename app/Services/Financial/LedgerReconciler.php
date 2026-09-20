<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * اثباتِ اینکه دفترِ مالی و موجودی‌ها با هم می‌خوانند.
 *
 * چرا این وجود دارد
 * ─────────────────
 * دو نمایشِ مستقل از «پول» در سیستم هست:
 *
 *   • `wallet_transactions` — دفترِ فقط-افزودنی (هر رویداد یک ردیف، هرگز پاک نمی‌شود)
 *   • `wallets.balance` / `balance_hold` — ستون‌هایی که *در جا* تغییر داده می‌شوند
 *
 * این دو باید همیشه معادل باشند، ولی هیچ کدی این را تضمین نمی‌کند. هر مسیری که
 * یکی را بدون دیگری بنویسد — یا وسطِ کار crash کند — یک اختلافِ دائمی می‌سازد که
 * *هیچ خطایی تولید نمی‌کند*: موجودی صرفاً غلط می‌شود و غلط می‌ماند.
 * FinancialHealthService جمعِ کل را گزارش می‌دهد ولی هرگز این تساوی را نمی‌سنجد.
 *
 * قاعده‌ی بازپخش (استخراج‌شده از همه‌ی نویسنده‌های موجود)
 * ───────────────────────────────────────────────────
 * پنج نقطه در کد به کیف پول می‌نویسند و همگی از این قواعد پیروی می‌کنند:
 *
 * | نوع            | وضعیت              | Δbalance | Δhold  | نویسنده                        |
 * |----------------|--------------------|----------|--------|--------------------------------|
 * | hold_release   | (هر چه)            | +مبلغ    | −مبلغ  | Engine::releaseHold()          |
 * | deposit        | hold               | ۰        | +مبلغ  | Engine::process('+', hold)     |
 * | deposit        | confirmed          | +مبلغ    | ۰      | Engine / Podium / تأیید ادمین  |
 * | deposit        | pending            | ۰        | ۰      | FundWalletAction (فیش)         |
 * | deposit        | rejected / failed  | ۰        | ۰      | فیشِ ردشده                     |
 * | withdraw       | hold               | ۰        | −مبلغ  | Engine::process('-', hold)     |
 * | withdraw       | confirmed          | −مبلغ    | ۰      | Engine / تسویه‌ی بانکی          |
 * | withdraw       | pending            | −مبلغ    | ۰      | DebitWalletAction (کسر فوری)   |
 * | withdraw       | rejected / failed  | ۰        | ۰      | کسر شد، بعد برگشت خورد         |
 *
 * دو نکته‌ی ظریف که قاعده را شکل داده‌اند:
 *
 *  1. `withdraw/pending` **مبلغ را کسر می‌کند** — برخلافِ شهود. RequestWithdrawalAction
 *     همان لحظه پول را برمی‌دارد و ردیف را pending می‌گذارد؛ تسویه‌ی بانکی فقط وضعیت
 *     را عوض می‌کند. رد شدنِ درخواست، پول را برمی‌گرداند (پس خالصِ rejected صفر است).
 *  2. `deposit/pending` **مبلغ را اضافه نمی‌کند** — فیشِ کاربر تا تأییدِ ادمین اثری
 *     روی موجودی ندارد. یعنی `pending` بسته به نوع، دو معنای متضاد دارد.
 *
 * چون این قواعد از کد استنتاج شده‌اند نه از یک قرارداد، هر ترکیبِ (نوع، وضعیت) که
 * اینجا مدل نشده باشد جداگانه گزارش می‌شود — یک ترکیبِ ناشناخته یعنی قاعده کهنه شده
 * و نتیجه‌ی بازپخش دیگر قابل اعتماد نیست. سکوت در آن حالت بدتر از نبودِ ابزار است.
 */
final class LedgerReconciler
{
    /** سفارش‌هایی که دیگر حرکتِ مالی ندارند — هولدِ باقی‌مانده روی این‌ها یعنی پولِ گیرافتاده. */
    private const TERMINAL_ORDER_STATUSES = [
        Order::STATUS_DELIVERED,
        Order::STATUS_CANCELLED,
    ];

    /**
     * گزارشِ کامل.
     *
     * @param  int|null  $userId  محدود کردن بررسیِ کیف‌ها به یک کاربر
     * @param  int|null  $orderId  محدود کردن بررسیِ سفارش‌ها به یک سفارش
     * @return array{
     *     wallets: array{checked:int, drifted:array<int, array<string, mixed>>},
     *     unknown_combinations: array<int, array<string, mixed>>,
     *     unbalanced_orders: array<int, array<string, mixed>>,
     *     orphan_holds: array<int, array<string, mixed>>,
     *     mispriced_orders: array<int, array<string, mixed>>,
     *     healthy: bool,
     *     problems: array<int, string>
     * }
     */
    public function report(?int $userId = null, ?int $orderId = null): array
    {
        $unknown = $this->unknownCombinations();
        $drifted = $this->driftedWallets($userId);
        $unbalanced = $this->unbalancedOrders($orderId);
        $orphans = $this->orphanHolds($orderId);
        $mispriced = $this->mispricedOrders($orderId);

        $problems = [];

        // اول از همه: اگر قاعده ناقص است، بقیه‌ی اعداد بی‌معنا هستند.
        foreach ($unknown as $combo) {
            $problems[] = sprintf(
                'ترکیبِ مدل‌نشده‌ی (%s / %s) با %d ردیف در دفتر هست — قاعده‌ی بازپخش کهنه شده و نتیجه‌ی زیر قابل اتکا نیست.',
                $combo['type'],
                $combo['status'],
                $combo['rows'],
            );
        }

        foreach ($drifted as $row) {
            $problems[] = sprintf(
                'کیف #%d (کاربر %d): موجودی %s است ولی دفتر %s می‌گوید (اختلاف %s) — هولد %s در برابر %s (اختلاف %s).',
                $row['wallet_id'], $row['user_id'],
                $row['stored_balance'], $row['expected_balance'], $row['balance_diff'],
                $row['stored_hold'], $row['expected_hold'], $row['hold_diff'],
            );
        }

        foreach ($unbalanced as $row) {
            $problems[] = sprintf(
                'سفارش %s (#%d): جمعِ جبریِ تراکنش‌ها %s است نه صفر — یعنی پول خلق یا نابود شده.',
                $row['order_number'] ?? '—', $row['order_id'], $row['net'],
            );
        }

        foreach ($orphans as $row) {
            $problems[] = sprintf(
                'سفارش %s (#%d) در وضعیت «%s» است ولی %s ریال هولدِ آزادنشده دارد (%d روز).',
                $row['order_number'] ?? '—', $row['order_id'], $row['order_status'],
                $row['stuck_hold'], $row['age_days'],
            );
        }

        foreach ($mispriced as $row) {
            $problems[] = sprintf(
                'سفارش %s (#%d): مبنای قیمت %s ریال است ولی ارزشِ ردیف‌ها %s ریال (اختلاف %s)%s.',
                $row['order_number'] ?? '—', $row['order_id'],
                $row['charged_basis'], $row['goods_value'], $row['difference'],
                $row['has_snapshot'] ? '' : ' — بدون اسنپ‌شاتِ official_price',
            );
        }

        return [
            'wallets' => [
                'checked' => $this->walletCount($userId),
                'drifted' => $drifted,
            ],
            'unknown_combinations' => $unknown,
            'unbalanced_orders' => $unbalanced,
            'orphan_holds' => $orphans,
            'mispriced_orders' => $mispriced,
            'healthy' => $problems === [],
            'problems' => $problems,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ۱. بازپخشِ دفتر در برابر موجودی‌ها
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * کیف‌هایی که موجودیِ ذخیره‌شده‌شان با بازپخشِ دفتر نمی‌خواند.
     *
     * محاسبه سمتِ دیتابیس انجام می‌شود و فقط ردیف‌های *مغایر* برگردانده می‌شوند —
     * یعنی حجمِ خروجی به تعداد خطاها بستگی دارد نه به تعداد کیف‌ها. مقایسه دقیق
     * است (بدون تلورانس): هر دو ستون decimal(20,0) هستند و چون موجودیِ ذخیره‌شده
     * همیشه عددِ صحیح است، round(B + a) با B + round(a) دقیقاً برابر است.
     *
     * @return array<int, array<string, mixed>>
     */
    public function driftedWallets(?int $userId = null): array
    {
        $ledger = DB::table('wallet_transactions')
            ->selectRaw('wallet_id')
            ->selectRaw($this->balanceExpression().' AS expected_balance')
            ->selectRaw($this->holdExpression().' AS expected_hold')
            ->groupBy('wallet_id');

        $query = DB::table('wallets')
            ->leftJoinSub($ledger, 'l', 'l.wallet_id', '=', 'wallets.'.Wallet::ID)
            ->select([
                'wallets.'.Wallet::ID.' as wallet_id',
                'wallets.'.Wallet::USER_ID.' as user_id',
                'wallets.'.Wallet::BALANCE.' as stored_balance',
                'wallets.'.Wallet::BALANCE_HOLD.' as stored_hold',
            ])
            // COALESCE: کیفی که هیچ تراکنشی ندارد باید موجودیِ صفر داشته باشد؛
            // بدون این، LEFT JOIN مقدار NULL می‌داد و مقایسه همیشه false می‌شد و
            // دقیقاً همان حالتِ «موجودی از هیچ‌جا آمده» را پنهان می‌کرد.
            ->selectRaw('COALESCE(l.expected_balance, 0) as expected_balance')
            ->selectRaw('COALESCE(l.expected_hold, 0) as expected_hold')
            // ⚠️ پرانتز الزامی است. `whereRaw` رشته را همان‌طور که هست می‌گذارد و
            // فیلترِ `--user` پایین‌تر با AND اضافه می‌شود؛ چون AND در SQL از OR
            // محکم‌تر می‌چسبد، بدون پرانتز شرط به این تبدیل می‌شد:
            //   balance <> expected  OR  (hold <> expected_hold AND user_id = ?)
            // یعنی `financial:doctor --user=X` هر کیفی را در کلِ سیستم که
            // موجودی‌اش مغایر بود گزارش می‌کرد، نه فقط کیفِ همان کاربر — یک
            // ابزارِ ممیزی که در حالتِ محدود، جوابِ نامحدود می‌داد.
            ->whereRaw('(wallets.'.Wallet::BALANCE.' <> COALESCE(l.expected_balance, 0)
                      OR wallets.'.Wallet::BALANCE_HOLD.' <> COALESCE(l.expected_hold, 0))')
            ->orderBy('wallets.'.Wallet::ID);

        if ($userId !== null) {
            $query->where('wallets.'.Wallet::USER_ID, $userId);
        }

        return $query->get()->map(fn ($row) => [
            'wallet_id' => (int) $row->wallet_id,
            'user_id' => (int) $row->user_id,
            'stored_balance' => (string) $row->stored_balance,
            'expected_balance' => (string) $row->expected_balance,
            'balance_diff' => $this->plain(Money::sub($row->stored_balance, $row->expected_balance)),
            'stored_hold' => (string) $row->stored_hold,
            'expected_hold' => (string) $row->expected_hold,
            'hold_diff' => $this->plain(Money::sub($row->stored_hold, $row->expected_hold)),
        ])->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ۲. جمعِ جبریِ هر سفارش باید صفر باشد
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * سفارش‌هایی که تراکنش‌هایشان به صفر نمی‌رسد.
     *
     * هر حرکتِ مالیِ سفارش جفت است: یک کیف بدهکار، کیفِ دیگر بستانکار، هر دو داخلِ
     * یک DB::transaction. پس جمعِ علامت‌دارِ همه‌ی ردیف‌های یک سفارش باید *دقیقاً*
     * صفر باشد — بدون اینکه لازم باشد فرمولِ تسویه (POO/PTS/سهم‌ها/VAT) اینجا
     * تکرار شود. هر عددِ غیرصفر یعنی یک پایه بدونِ پایه‌ی متناظرش ثبت شده.
     *
     * `hold_release` صفر حساب می‌شود: پول را بینِ دو سطلِ *همان* کیف جابه‌جا می‌کند
     * و از عرضه‌ی پول کم یا زیاد نمی‌کند.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unbalancedOrders(?int $orderId = null): array
    {
        $net = sprintf(
            'SUM(CASE WHEN type = %s THEN amount WHEN type = %s THEN -amount ELSE 0 END)',
            $this->literal(WalletTransaction::TYPE_DEPOSIT),
            $this->literal(WalletTransaction::TYPE_WITHDRAW),
        );

        $query = DB::table('wallet_transactions')
            ->selectRaw('order_id')
            ->selectRaw("$net AS net")
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw("$net <> 0")
            ->orderBy('order_id');

        if ($orderId !== null) {
            $query->where('order_id', $orderId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // ⚠️ دفترِ بدهی هم بخشی از همین معادله است.
        //
        // وقتی شرکت اضافه‌بار را به‌صورت اعتبار جلو می‌اندازد، کیف پول عمداً
        // نامتوازن می‌شود: `creditSystem(deltaPOO)` کامل خورده ولی از خریدار
        // فقط `available` کسر شده و مابقی به `debt_entries` رفته. آن اختلاف
        // «پولِ خلق‌شده» نیست — طلبی است که ثبت شده.
        //
        // بدون این تفریق، هر سفارشِ اعتباری برای همیشه در گزارشِ
        // `financial:doctor` به‌عنوان فساد دفتر می‌نشست، و هشداری که همیشه
        // روشن است دیگر هشدار نیست: مغایرتِ واقعی لای همین‌ها گم می‌شد.
        $debt = DB::table('debt_entries')
            ->selectRaw('order_id, SUM(amount) AS owed')
            ->whereNotNull('order_id')
            ->whereIn('order_id', $rows->pluck('order_id')->all())
            ->groupBy('order_id')
            ->pluck('owed', 'order_id');

        $rows = $rows->filter(
            fn ($row) => bccomp(
                (string) $row->net,
                (string) ($debt[$row->order_id] ?? '0'),
                2
            ) !== 0
        )->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $numbers = $this->orderNumbers($rows->pluck('order_id')->all());

        return $rows->map(fn ($row) => [
            'order_id' => (int) $row->order_id,
            'order_number' => $numbers[(int) $row->order_id] ?? null,
            'net' => (string) $row->net,
            // طلبِ ثبت‌شده‌ی همین سفارش، تا گزارش بگوید چقدرِ اختلاف توضیح دارد.
            'debt' => (string) ($debt[$row->order_id] ?? '0'),
        ])->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ۳. هولدهای یتیم
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * سفارش‌هایی که کارشان تمام شده ولی هنوز هولدِ آزادنشده دارند.
     *
     * هولد یعنی پول از دسترسِ صاحبش خارج شده و منتظرِ آزادسازی است. اگر سفارش به
     * وضعیتِ نهایی رسیده و هولد باقی مانده، پولِ کسی بدونِ هیچ رویدادِ آینده‌ای
     * قفل شده — کاربر آن را نمی‌بیند و هیچ‌کس هم خبردار نمی‌شود.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orphanHolds(?int $orderId = null): array
    {
        $query = DB::table('wallet_transactions')
            ->join('orders', 'orders.'.Order::ID, '=', 'wallet_transactions.order_id')
            ->selectRaw('wallet_transactions.order_id')
            ->selectRaw('orders.'.Order::ORDER_NUMBER.' AS order_number')
            ->selectRaw('orders.status AS order_status')
            ->selectRaw('MAX(wallet_transactions.created_at) AS last_movement')
            ->selectRaw($this->holdExpression('wallet_transactions.').' AS stuck_hold')
            ->whereIn('orders.status', self::TERMINAL_ORDER_STATUSES)
            ->groupBy('wallet_transactions.order_id', 'orders.'.Order::ORDER_NUMBER, 'orders.status')
            ->havingRaw($this->holdExpression('wallet_transactions.').' <> 0')
            ->orderBy('wallet_transactions.order_id');

        if ($orderId !== null) {
            $query->where('wallet_transactions.order_id', $orderId);
        }

        return $query->get()->map(fn ($row) => [
            'order_id' => (int) $row->order_id,
            'order_number' => $row->order_number,
            'order_status' => (string) $row->order_status,
            'stuck_hold' => (string) $row->stuck_hold,
            // ترتیبِ عملوندها عمدی است: Carbon 3 (لاراول ۱۳) عددِ **علامت‌دار**
            // برمی‌گرداند و Carbon 2 قدرمطلق. با `now()->diffInDays($past)` سنِ
            // هر هولدِ گیرکرده منفی گزارش می‌شد — یعنی گزارشِ financial:doctor
            // قدیمی‌ترین هولد را جوان‌ترین نشان می‌داد. از گذشته به now هر دو
            // نسخه مثبت می‌دهند.
            'age_days' => $row->last_movement
                ? (int) Carbon::parse($row->last_movement)->diffInDays(now())
                : 0,
        ])->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ۴. مبنای قیمت باید کلِ کالای سفارش را نمایندگی کند
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * سفارش‌هایی که مبنای قیمتشان با ارزشِ واقعیِ کالا نمی‌خواند.
     *
     * تسویه با `OP × weight_before_scale` کار می‌کند، پس این حاصل‌ضرب باید برابرِ
     * جمعِ ارزشِ ردیف‌ها (`Σ order_items.total_price`) باشد.
     *
     * چرا لازم است: تا پیش از این هیچ‌کس `official_price` را نمی‌نوشت و هر سه مسیرِ
     * تسویه روی فالبکِ `items->first()->unit_price` می‌افتادند — یعنی **کلِ تناژ به
     * قیمتِ ردیفِ اول** حساب می‌شد. گاردِ `assertSingleSupplier` این را نمی‌گرفت،
     * چون فقط تعدادِ *تأمین‌کننده* را می‌سنجد نه تعدادِ *ردیف*؛ سبدی با دو سایز
     * میلگرد از یک کارخانه کاملاً عادی است و از گارد رد می‌شود.
     *
     * این بررسی عمداً همان فالبکِ کد را بازتولید می‌کند (`OP > 0 ? OP : قیمتِ ردیف
     * اول`) تا سفارش‌های *قدیمیِ* بدون اسنپ‌شات را هم بسنجد — نه فقط سفارش‌های تازه.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mispricedOrders(?int $orderId = null): array
    {
        $tolerance = (float) config('ops.financial.pricing_tolerance', 1000);

        // مبنای مؤثر = دقیقاً همان چیزی که ProcessPreScalePaymentAction می‌خواند.
        $effectiveOp = '(CASE WHEN o.official_price > 0 THEN o.official_price ELSE ('
            .'SELECT oi2.unit_price FROM order_items oi2 WHERE oi2.order_id = o.id ORDER BY oi2.id LIMIT 1'
            .') END)';

        $goodsValue = '(SELECT SUM(oi.total_price) FROM order_items oi WHERE oi.order_id = o.id)';

        $query = DB::table('orders as o')
            ->selectRaw('o.id, o.order_number, o.official_price, o.weight_before_scale')
            ->selectRaw("$effectiveOp AS effective_op")
            ->selectRaw("$goodsValue AS goods_value")
            ->selectRaw("$effectiveOp * o.weight_before_scale AS charged_basis")
            ->whereRaw('o.weight_before_scale > 0')
            ->whereRaw("$goodsValue IS NOT NULL")
            // WHERE و نه HAVING: کوئریِ بیرونی هیچ GROUP BY ندارد (تجمیع داخلِ
            // زیرکوئری‌های همبسته است)، و MariaDB در آن حالت HAVING روی ستونِ
            // غیرگروهی را رد می‌کند: «Non-grouping field used in HAVING clause».
            //
            // ‏`+ 0.5 × وزن` سهمِ **ثابت‌شده‌ی** گردکردن است، نه شل‌کردنِ آستانه:
            // `official_price` قیمتِ هر کیلوست و ستونش از مهاجرتِ ریالِ صحیح
            // `decimal(20,0)` است، پس تا نیم ریال در هر کیلو گرد می‌شود — روی ۱۰
            // تن یعنی تا ۵٬۰۰۰ ریال، چند برابرِ آستانه‌ی ثابتِ ۱٬۰۰۰ ریالی. بدونِ
            // این جمله هر سفارشِ چندردیفیِ سنگینی که میانگینِ وزنی‌اش رُند نباشد
            // «بدقیمت» گزارش می‌شد؛ هشداری که همیشه می‌زند، هشدار نیست. مبنای
            // واقعاً غلط (قیمتِ ردیفِ اول) هزاران ریال در هر کیلو فاصله دارد و
            // همچنان گزارش می‌شود.
            ->whereRaw("ABS(($effectiveOp * o.weight_before_scale) - $goodsValue) > ? + (0.5 * o.weight_before_scale)", [$tolerance])
            ->orderBy('o.id');

        if ($orderId !== null) {
            $query->where('o.id', $orderId);
        }

        return $query->get()->map(fn ($row) => [
            'order_id' => (int) $row->id,
            'order_number' => $row->order_number,
            'goods_value' => $this->plain((string) $row->goods_value),
            'charged_basis' => $this->plain((string) $row->charged_basis),
            'difference' => $this->plain(Money::sub($row->charged_basis, $row->goods_value)),
            'has_snapshot' => (float) $row->official_price > 0,
        ])->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ۵. گاردِ قاعده
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ترکیب‌های (نوع، وضعیت) که قاعده‌ی بازپخش آن‌ها را نمی‌شناسد.
     *
     * بدون این، یک ردیف با نوعِ ناشناخته در شاخه‌ی `ELSE 0` می‌افتاد و *بی‌صدا* صفر
     * فرض می‌شد — یعنی ابزار «سالم» گزارش می‌داد در حالی که پولِ آن ردیف اصلاً
     * شمرده نشده بود. (`fee_hold` و `fee_release` امروز توسط هیچ کدی ساخته
     * نمی‌شوند ولی ثابتشان هنوز هست؛ اگر روزی برگردند، اینجا لو می‌روند.)
     *
     * @return array<int, array<string, mixed>>
     */
    public function unknownCombinations(): array
    {
        return DB::table('wallet_transactions')
            ->select(['type', 'status'])
            ->selectRaw('COUNT(*) AS rows_count')
            ->groupBy('type', 'status')
            ->get()
            ->reject(fn ($row) => $this->isModelled((string) $row->type, (string) $row->status))
            ->map(fn ($row) => [
                'type' => (string) $row->type,
                'status' => (string) $row->status,
                'rows' => (int) $row->rows_count,
            ])
            ->values()
            ->all();
    }

    private function isModelled(string $type, string $status): bool
    {
        if ($type === WalletTransaction::TYPE_HOLD_RELEASE) {
            return true; // وضعیت برای این نوع بی‌اثر است
        }

        if (! in_array($type, [WalletTransaction::TYPE_DEPOSIT, WalletTransaction::TYPE_WITHDRAW], true)) {
            return false;
        }

        return in_array($status, [
            WalletTransaction::STATUS_HOLD,
            WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::STATUS_PENDING,
            WalletTransaction::STATUS_REJECTED,
            WalletTransaction::STATUS_FAILED,
        ], true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // عبارت‌های SQL
    // ─────────────────────────────────────────────────────────────────────────

    /** اثرِ هر ردیف روی `balance` — ترتیبِ WHEN اهمیت دارد (hold_release اول). */
    private function balanceExpression(string $prefix = ''): string
    {
        return sprintf(
            'SUM(CASE
                WHEN %1$stype = %2$s THEN %1$samount
                WHEN %1$sstatus = %3$s THEN 0
                WHEN %1$stype = %4$s AND %1$sstatus = %5$s THEN %1$samount
                WHEN %1$stype = %6$s AND %1$sstatus IN (%5$s, %7$s) THEN -%1$samount
                ELSE 0
            END)',
            $prefix,
            $this->literal(WalletTransaction::TYPE_HOLD_RELEASE),
            $this->literal(WalletTransaction::STATUS_HOLD),
            $this->literal(WalletTransaction::TYPE_DEPOSIT),
            $this->literal(WalletTransaction::STATUS_CONFIRMED),
            $this->literal(WalletTransaction::TYPE_WITHDRAW),
            $this->literal(WalletTransaction::STATUS_PENDING),
        );
    }

    /** اثرِ هر ردیف روی `balance_hold`. */
    private function holdExpression(string $prefix = ''): string
    {
        return sprintf(
            'SUM(CASE
                WHEN %1$stype = %2$s THEN -%1$samount
                WHEN %1$sstatus = %3$s AND %1$stype = %4$s THEN %1$samount
                WHEN %1$sstatus = %3$s AND %1$stype = %5$s THEN -%1$samount
                ELSE 0
            END)',
            $prefix,
            $this->literal(WalletTransaction::TYPE_HOLD_RELEASE),
            $this->literal(WalletTransaction::STATUS_HOLD),
            $this->literal(WalletTransaction::TYPE_DEPOSIT),
            $this->literal(WalletTransaction::TYPE_WITHDRAW),
        );
    }

    /**
     * درج امنِ یک ثابتِ کلاس داخلِ SQL.
     *
     * این مقادیر ورودیِ کاربر نیستند (همه ثابتِ WalletTransaction هستند)، ولی چون
     * داخل عبارت‌های CASE می‌روند و binding در چند selectRaw/havingRaw ترتیبش
     * شکننده است، الگو صریحاً تأیید می‌شود تا هیچ رشته‌ی غیرمنتظره‌ای به SQL نرسد.
     */
    private function literal(string $value): string
    {
        if (preg_match('/^[a-z_]+$/', $value) !== 1) {
            throw new \InvalidArgumentException("LedgerReconciler: unexpected ledger constant '{$value}'.");
        }

        return "'{$value}'";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // کمکی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * حذفِ صفرهای اعشاریِ bcmath برای نمایش.
     *
     * `Money::sub` همیشه با SCALE=10 برمی‌گردد، ولی مبالغِ این پروژه ریالِ صحیح‌اند
     * و «۵۰۰۰۰۰۰۰۰.۰۰۰۰۰۰۰۰۰۰» فقط خواندنِ جدول را سخت می‌کند. شرطِ وجودِ نقطه
     * لازم است — بدون آن rtrim روی «۱۰۰» عدد را به «۱» تبدیل می‌کرد.
     */
    private function plain(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function walletCount(?int $userId): int
    {
        $query = DB::table('wallets');

        if ($userId !== null) {
            $query->where(Wallet::USER_ID, $userId);
        }

        return (int) $query->count();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string|null>
     */
    private function orderNumbers(array $ids): array
    {
        return DB::table('orders')
            ->whereIn(Order::ID, $ids)
            ->pluck(Order::ORDER_NUMBER, Order::ID)
            ->all();
    }
}
