<?php

namespace App\Services\Invoice;

use App\Constants\FinancialCodes;
use App\Models\Badge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Financial\SettlementCalculatorService;
use App\Support\JalaliDate;
use App\Support\Money;
use App\Traits\ConvertNumberToWord;

/**
 * مسئولیت: آماده‌سازی آرایه داده برای ارسال به Blade view.
 *
 * این کلاس هیچ کاری با PDF، فایل، یا دیتابیس ندارد —
 * فقط Order را می‌گیرد و یک آرایه قابل ارسال به view برمی‌گرداند.
 */
class InvoiceDataBuilder
{
    use ConvertNumberToWord;

    /**
     * اطلاعات ثابت فروشنده — دقیقاً مطابق طراحی اکسل تیم UI/UX.
     */
    private const SELLER_INFO = [
        'name' => 'شرکت نرم افزاری سازه اسپادانا',
        'economic_code' => '411347688653',
        'reg_id' => '31106',
        'national_id' => '10260516722',
        'city' => 'اصفهان/اصفهان',
        'address' => 'پارک علم و فناوری/خیابان سلمان فارسی(مشتاق سوم)/بعد از پل شهرستان/ساختمان شهرک علمی تحقیقاتی/ طبقه اول/ واحد 114',
        'phone' => '03134005000',
        'fax' => '03134005000',
        'postal_code' => '8158184500',
    ];

    /**
     * اسنادی که فقط تأمین‌کننده می‌بیند (`OrderDocumentCatalog` → AUDIENCE_SUPPLIER). مدیریت، دورِ پنجم،
     * پرسشِ ۷ (۱۴۰۵/۰۷/۱۴): «مبلغِ قابلِ دریافتِ تأمین‌کننده (پس از کارمزد)؛ مبلغِ خریدار را نبیند.»
     * دستورِ بارگیری (C) و بارنامه (K) مشترکِ خریدار و تأمین‌کننده‌اند و بیرونِ این فهرست می‌مانند.
     */
    private const SUPPLIER_DOCS = [
        FinancialCodes::DOC_REPORT_SUPPLIER,
        FinancialCodes::DOC_RESERVE_REPORT,
        FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,
        FinancialCodes::DOC_CANCEL_REPORT,
        FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER,
        FinancialCodes::DOC_TAX_INVOICE_SUPPLIER,
    ];

    /**
     * اسنادِ الیت‌یار — مدیریت، دورِ ششم، پرسشِ ۸ (۱۴۰۵/۰۷/۱۵): «مبلغِ پرداختیِ خریدار در هیچ جا به
     * الیت‌یار یا تأمین‌کننده نشان نده.» فقط کارمزدِ خودِ الیت‌یار.
     */
    private const ELITE_YAR_DOCS = [
        FinancialCodes::DOC_ELITE_YAR_REPORT,
        FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR,
    ];

    /**
     * فاکتورهای خریدار — با واحدِ پولِ سایت (علی ۱۴۰۵/۰۷/۱۹: «فاکتور رسمی PDF تابع تنظیم سایت»): پیش‌فاکتور،
     * فاکتور فروش، فاکتور نهایی و فاکتور اصلاحی. بقیه‌ی اسناد ریالی می‌مانند — اسنادِ مالیاتی (O/P/Q) نسخه‌ی
     * کاغذیِ صورتحسابِ مودیان‌اند و مودیان همیشه ریال می‌گیرد؛ اسنادِ تأمین‌کننده/الیت‌یار/مشترک تصمیمِ
     * جدایی می‌خواهند (رکوردِ BE3).
     */
    private const SITE_UNIT_DOCS = [
        FinancialCodes::DOC_PRE_INVOICE,
        FinancialCodes::DOC_RECEIPT_OWNER,
        FinancialCodes::DOC_FINAL_RECEIPT_OWNER,
        FinancialCodes::DOC_MODIFIED_INVOICE_OWNER,
    ];

    public function __construct(private readonly SettlementCalculatorService $settlement) {}

    /**
     * آرایه کامل داده مورد نیاز Blade view را برمی‌گرداند.
     *
     * پیش‌نیاز: $order باید relations زیر را load کرده باشد:
     *   items.productVariant.product, placer.profile, address.city, address.province
     */
    public function build(Order $order, string $docType, string $invoiceNumber): array
    {
        $buyerProfile = $order->placer?->profile;
        $supplierView = in_array($docType, self::SUPPLIER_DOCS, true);
        $eliteYarView = in_array($docType, self::ELITE_YAR_DOCS, true);
        $lineItems = match (true) {
            $supplierView => $this->buildSupplierLineItems($order),
            $eliteYarView => [],
            default => $this->buildLineItems($order),
        };
        $totals = match (true) {
            $supplierView => $this->buildSupplierTotals($order, $lineItems),
            $eliteYarView => $this->buildEliteYarTotals($order),
            default => $this->buildTotals($order, $lineItems),
        };

        $amountToConvert = ($supplierView || $eliteYarView) ? (float) $totals['final'] : match ($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT => (float) $order->payment_to_reserve,
            default => (float) $order->final_amount,
        };

        $moneyUnit = in_array($docType, self::SITE_UNIT_DOCS, true) ? Money::unitFor(Money::APP_SITE) : Money::RIAL;

        return [
            'invoice_number' => $invoiceNumber,
            // واحدِ مبالغِ سند — `pdf/partials/items_section` و `payable_row` با همین می‌نویسند.
            'money_unit' => $moneyUnit,
            'order' => $order,
            'seller' => self::SELLER_INFO,
            // Brand artwork from the UI/UX design (embedded so the PDF is self-contained).
            'brand_logo' => $this->assetDataUri('elitezone-logo.png'),
            'brand_web' => $this->assetDataUri('elitezone-web.png'),
            'buyer' => [
                'name' => $this->resolveBuyerName($buyerProfile),
                'economic_code' => $buyerProfile?->economic_code ?? '-',
                'national_id' => $buyerProfile?->national_code
                    ?? $buyerProfile?->national_id
                    ?? $order->placer?->national_code
                    ?? '-',
                'phone' => $buyerProfile?->phone ?? $order->placer?->mobile ?? '-',
                'postal_code' => $order->address?->postal_code ?? '-',
                'address' => $order->address?->postal_address
                    ?? $order->address?->address
                    ?? '-',
                'province' => $order->address?->province?->name ?? '',
                'city' => $order->address?->city?->name ?? '',
            ],
            'line_items' => $lineItems,
            'totals' => $totals,
            'doc_type' => $docType,
            'supplier_view' => $supplierView,
            'elite_yar_view' => $eliteYarView,
            'payable_label' => $supplierView ? 'مبلغ قابل دریافت:' : 'مبلغ قابل پرداخت:',
            'date_shamsi' => JalaliDate::format(now(), 'Y/m/d'),
            // اعتبار پیش‌فاکتور: تا انقضای رزرو سفارش، و در نبود آن، ۲ روز پس از صدور.
            'validity_shamsi' => JalaliDate::format($order->reservation_expires_at ?? now()->addDays(2), 'Y/m/d'),
            'amount_words' => $this->amountWords($amountToConvert, $moneyUnit),
            'payment_label' => match ($order->payment_status) {
                Order::PAYMENT_PAID => 'پرداخت شده',
                Order::PAYMENT_FAILED => 'ناموفق',
                Order::PAYMENT_PENDING => 'پرداخت نشده',
                default => 'پرداخت نشده',
            },
            'payment_status_class' => $order->payment_status === Order::PAYMENT_PAID ? 'paid' : 'unpaid',
        ];
    }

    /**
     * مبلغ به حروف، بی‌واحد (قالب واحد را کنارش می‌نویسد). تومان دقیق است: «… و پنج دهم» وقتی ریال بر ۱۰
     * بخش‌پذیر نیست — همان قاعده‌ی `Money::amountIn`.
     */
    private function amountWords(float $amount, string $unit): string
    {
        $rials = (int) round($amount);

        if ($unit !== Money::TOMAN) {
            return $this->numberToWords($rials);
        }

        $whole = intdiv($rials, 10);
        $tenth = abs($rials % 10);
        $parts = [];
        if ($whole !== 0 || $tenth === 0) {
            $parts[] = $this->numberToWords($whole);
        }
        if ($tenth !== 0) {
            $parts[] = $this->numberToWords($tenth).' دهم';
        }

        return implode(' و ', $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildLineItems(Order $order): array
    {
        $lines = [];

        foreach ($order->items as $index => $item) {
            $lines[] = $this->normalizeLineItem($item, $index + 1);
        }

        if ($lines === []) {
            return [];
        }

        $sumGross = array_sum(array_column($lines, 'gross'));
        $orderDiscount = (float) $order->discount_amount;
        $orderVat = (float) $order->vat_amount;

        foreach ($lines as &$line) {
            $ratio = $sumGross > 0
                ? ($line['gross'] / $sumGross)
                : (1 / count($lines));
            $line['discount'] = (int) round($orderDiscount * $ratio);
            $line['after_discount'] = max(0, (int) round($line['gross'] - $line['discount']));
            $line['tax'] = (int) round($orderVat * $ratio);
            $line['final'] = $line['after_discount'] + $line['tax'];
        }
        unset($line);

        return $lines;
    }

    /**
     * @return array<string, float|int>
     */
    private function buildTotals(Order $order, array $lineItems): array
    {
        $subtotal = (float) $order->subtotal;
        $discount = (float) $order->discount_amount;
        $vat = (float) $order->vat_amount;
        $final = (float) $order->final_amount;

        // سطرهای سطحِ سفارش (پرسشِ ۲ مدیریت، ۱۴۰۵/۰۷/۱۳): کرایه (بی‌مالیات — مالیاتش در
        // ستونِ مالیات پخش شده)، تخفیفِ ویژه‌ی خریدار و کوپن. با این سه،
        // جمعِ ستونِ «مبلغ کل» + کرایه − تخفیف ویژه − کوپن = مبلغِ قابل پرداخت، تا ریال.
        $orderLevel = [
            'shipping' => (float) ($order->shipping_cost ?? 0),
            'buyer_discount' => (float) ($order->getAttribute(Order::BUYER_DISCOUNT_AMOUNT) ?? 0),
            'elite_yar_discount' => (float) ($order->getAttribute(Order::ELITE_YAR_DISCOUNT_AMOUNT) ?? 0),
            'coupon_discount' => (float) ($order->getAttribute(Order::COUPON_DISCOUNT_AMOUNT) ?? 0),
        ];

        if ($lineItems !== []) {
            return $orderLevel + [
                'quantity' => array_sum(array_column($lineItems, 'quantity')),
                'gross' => $subtotal > 0 ? $subtotal : array_sum(array_column($lineItems, 'gross')),
                'discount' => $discount,
                'after_discount' => max(0, $subtotal - $discount),
                'tax' => $vat,
                'goods_total' => array_sum(array_column($lineItems, 'final')),
                'final' => $final > 0 ? $final : array_sum(array_column($lineItems, 'final')),
            ];
        }

        return $orderLevel + [
            'quantity' => 0,
            'gross' => $subtotal,
            'discount' => $discount,
            'after_discount' => max(0, $subtotal - $discount),
            'tax' => $vat,
            'goods_total' => max(0, $subtotal - $discount) + $vat,
            'final' => $final,
        ];
    }

    /**
     * ردیف‌ها با قیمتِ تأمین‌کننده (پرسشِ ۷): قیمتِ ثبت‌شده‌ی هر ردیف پیش از فیلترهای نقش، بی‌تخفیف،
     * با مالیاتِ کالا. پس از باسکول وزن‌ها به نسبتِ وزنِ واقعی مقیاس می‌شوند — همان وزنی که PTSM
     * با آن حساب شد — تا جمعِ ردیف‌ها و مبلغِ قابل‌دریافت روی یک وزن باشند.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSupplierLineItems(Order $order): array
    {
        $fallbackPrice = $this->settlement->supplierOpFor($order, (float) ($order->{Order::OFFICIAL_PRICE} ?? 0));
        $vat = $this->settlement->resolveVatRate($order);
        $scale = $this->supplierWeightScale($order);
        $lines = [];

        foreach ($order->items as $index => $item) {
            $line = $this->normalizeLineItem($item, $index + 1);
            $weight = (float) ($item->weight ?? 0) * $scale;
            $unitPrice = (float) ($item->getAttribute(OrderItem::SUPPLIER_UNIT_PRICE) ?? 0);
            $unitPrice = $unitPrice > 0 ? $unitPrice : $fallbackPrice;

            if ($weight > 0) {
                $line['quantity'] = round($weight, 3);
            }

            $line['unit_price'] = $unitPrice;
            $line['gross'] = (int) round($unitPrice * ($weight > 0 ? $weight : (float) $line['quantity']));
            $line['discount'] = 0;
            $line['after_discount'] = $line['gross'];
            $line['tax'] = (int) round($line['gross'] * $vat / 100);
            $line['final'] = $line['gross'] + $line['tax'];
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * جمع‌ها برای تأمین‌کننده: کالا + کرایه − کارمزدِ قراردادی = مبلغِ قابل‌دریافت (PTS/PTSM).
     * کارمزد به‌صورتِ تفاضل نوشته می‌شود تا جمع تا ریال با مبلغِ واریزی یکی باشد؛ سطرهای خریدار
     * (تخفیفِ ویژه، تخفیفِ الیت‌یار، کوپن) صفرند و در قالب پنهان می‌مانند.
     *
     * @return array<string, mixed>
     */
    private function buildSupplierTotals(Order $order, array $lineItems): array
    {
        $vat = $this->settlement->resolveVatRate($order);
        $transVat = (bool) ($order->{Order::TRANS_VAT} ?? true);
        $trans = (float) ($order->{Order::SHIPPING_COST} ?? 0);
        $shipping = (float) round($transVat ? $trans * (1 + $vat / 100) : $trans);

        $goodsNet = (float) array_sum(array_column($lineItems, 'gross'));
        $goodsTotal = (float) array_sum(array_column($lineItems, 'final'));
        $receivable = $this->supplierReceivable($order, $trans, $vat);
        $tc = (float) ($order->{Order::COMMISSION_RATE} ?? 0);

        return [
            'quantity' => array_sum(array_column($lineItems, 'quantity')),
            'gross' => $goodsNet,
            'discount' => 0.0,
            'after_discount' => $goodsNet,
            'tax' => (float) array_sum(array_column($lineItems, 'tax')),
            'goods_total' => $goodsTotal,
            'shipping' => $shipping,
            'shipping_label' => $transVat ? 'هزینه حمل (با مالیات آن):' : 'هزینه حمل:',
            'buyer_discount' => 0.0,
            'elite_yar_discount' => 0.0,
            'coupon_discount' => 0.0,
            'commission' => max(0.0, $goodsTotal + $shipping - $receivable),
            'commission_label' => $tc > 0
                ? 'کسر کارمزد قراردادی ('.rtrim(rtrim(number_format($tc, 2, '.', ''), '0'), '.').'٪، با مالیات آن):'
                : 'کسر کارمزد قراردادی (با مالیات آن):',
            'final' => $receivable,
        ];
    }

    /** PTS (پیش از باسکول) یا PTSM (پس از آن)؛ پیش از پرداخت (سندِ رزرو) با همان فرمولِ پرداخت. */
    private function supplierReceivable(Order $order, float $trans, float $vat): float
    {
        $paid = (float) ($order->{Order::PAYMENT_TO_SUPPLIER} ?? 0);
        if ($paid > 0) {
            return $paid;
        }

        $supplierId = $order->items->first()?->supplier_user_id;
        $tc = (float) ($order->{Order::COMMISSION_RATE} ?? 0);
        $tc = $tc > 0 ? $tc : $this->settlement->tcPercentageForSupplier($supplierId !== null ? (int) $supplierId : null);

        return $this->settlement->calculatePTS(
            $trans,
            $this->settlement->supplierOpFor($order, (float) ($order->{Order::OFFICIAL_PRICE} ?? 0)),
            (float) ($order->{Order::WEIGHT_BEFORE_SCALE} ?? 0),
            $tc,
            0.0,
            0.0,
            $vat,
            (bool) ($order->{Order::TRANS_COMMISSION} ?? true),
            (bool) ($order->{Order::TRANS_VAT} ?? true),
        );
    }

    /**
     * فقط کارمزدِ الیت‌یار؛ هیچ عددی از خریدار (پرسشِ ۸ِ دورِ ششم).
     *
     * @return array{elite_yar_commission: float, is_estimate: bool, final: float}
     */
    private function buildEliteYarTotals(Order $order): array
    {
        $isEstimate = $order->{Order::WEIGHING_COMPLETED_AT} === null;
        $commission = $this->eliteYARCommission($order);

        return ['elite_yar_commission' => $commission, 'is_estimate' => $isEstimate, 'final' => $commission];
    }

    /**
     * کارمزدِ ناخالصِ الیت‌یار (با مالیات). پس از باسکول همان `payment_to_elite_yar` که واقعاً ثبت شد؛
     * پیش از آن تخمین با همان فرمولِ `WeighbridgeVatRouter`: (POO − PTS) × CO / (CO + SSG) با نرخ‌های
     * اسنپ‌شاتِ سفارش. الیت‌یارِ پرداخت‌کننده (خریدِ خودش) کارمزد ندارد. پیش از این دو سندِ D و Q
     * `final_amount × 0.006` می‌نوشتند — ضریبی ثابت که با قراردادِ تأمین‌کننده و نشان ربطی نداشت.
     */
    public function eliteYARCommission(Order $order): float
    {
        if ($order->{Order::WEIGHING_COMPLETED_AT} !== null) {
            return (float) ($order->{Order::PAYMENT_TO_ELITE_YAR} ?? 0);
        }

        $eliteYarId = (int) ($order->{Order::ELITE_YAR_USER_ID} ?? 0);
        if ($eliteYarId === 0 || $eliteYarId === (int) $order->payerUserId()) {
            return 0.0;
        }

        $pool = (float) ($order->{Order::PAYMENT_OF_OWNER} ?? 0) - (float) ($order->{Order::PAYMENT_TO_SUPPLIER} ?? 0);
        if ($pool <= 0) {
            return 0.0;
        }

        // همان قاعده‌ی پرداخت و `WeighbridgeReconciler`: الیت‌یارِ بی‌نشان = pioneer.
        $eliteYar = $order->eliteYAR;
        $badge = $eliteYar instanceof User ? $eliteYar->badge : null;
        $rates = $this->settlement->getCommissionRatesForOrder($order, $badge instanceof Badge ? (string) $badge->code : 'pioneer');
        $coPlusSsg = $rates['co_final_rate'] + $rates['ssg_final_rate'];

        return $coPlusSsg > 0 ? (float) round($pool * $rates['co_final_rate'] / $coPlusSsg) : 0.0;
    }

    /** وزنِ واقعی ÷ وزنِ سفارش پس از باسکول؛ پیش از آن ۱. */
    private function supplierWeightScale(Order $order): float
    {
        $before = (float) ($order->{Order::WEIGHT_BEFORE_SCALE} ?? 0);
        $after = (float) ($order->{Order::WEIGHT_AFTER_SCALE} ?? 0);

        return $order->{Order::WEIGHING_COMPLETED_AT} !== null && $before > 0 && $after > 0 ? $after / $before : 1.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeLineItem(OrderItem $item, int $row): array
    {
        $unitPrice = (float) $item->unit_price;
        $totalPrice = (float) $item->total_price;
        $weight = (float) ($item->weight ?? 0);
        $qty = (float) ($item->quantity ?? 0);
        $unitName = $item->unit_name ?: 'عدد';

        if ($weight > 0) {
            $displayQty = $weight;
            $displayUnit = 'کیلوگرم';
        } elseif ($qty > 0) {
            $displayQty = $qty;
            $displayUnit = $unitName;
        } else {
            $displayQty = $this->sumQuantityDetails($item->quantity_details);
            $displayUnit = $unitName;
        }

        if ($displayQty <= 0 && $unitPrice > 0 && $totalPrice > 0) {
            $displayQty = $totalPrice / $unitPrice;
        }

        $gross = $totalPrice > 0 ? $totalPrice : ($displayQty * $unitPrice);

        $name = $item->product_name
            ?? optional($item->productVariant?->product)->name
            ?? optional($item->productVariant)->name
            ?? 'محصول';

        return [
            'row' => $row,
            'name' => $name,
            'quantity' => $displayQty,
            'unit' => $displayUnit,
            'unit_price' => $unitPrice,
            'gross' => (int) round($gross),
            'note' => '',
        ];
    }

    private function sumQuantityDetails(mixed $details): float
    {
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        if (! is_array($details)) {
            return 0;
        }

        return (float) array_sum(array_map('floatval', array_values($details)));
    }

    /**
     * Read a PDF brand asset and return it as a base64 data URI so the rendered PDF
     * carries the image inline (no filesystem/URL dependency at view time).
     */
    private function assetDataUri(string $file): string
    {
        $path = resource_path('views/pdf/assets/'.$file);

        if (! is_file($path)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }

    private function resolveBuyerName(?UserProfile $profile): string
    {
        if (! $profile) {
            return '';
        }

        $name = trim(($profile->name ?? '').' '.($profile->family ?? ''));

        if ($name !== '') {
            return $name;
        }

        return (string) ($profile->company ?? '');
    }
}
