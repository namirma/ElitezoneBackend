<?php

namespace App\Services\Invoice;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserProfile;
use App\Services\Financial\SettlementCalculatorService;
use App\Support\JalaliDate;
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
        $lineItems = $supplierView ? $this->buildSupplierLineItems($order) : $this->buildLineItems($order);
        $totals = $supplierView ? $this->buildSupplierTotals($order, $lineItems) : $this->buildTotals($order, $lineItems);

        $amountToConvert = $supplierView ? (float) $totals['final'] : match ($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT => (float) $order->payment_to_reserve,
            default => (float) $order->final_amount,
        };

        return [
            'invoice_number' => $invoiceNumber,
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
            'payable_label' => $supplierView ? 'مبلغ قابل دریافت:' : 'مبلغ قابل پرداخت:',
            'date_shamsi' => JalaliDate::format(now(), 'Y/m/d'),
            // اعتبار پیش‌فاکتور: تا انقضای رزرو سفارش، و در نبود آن، ۲ روز پس از صدور.
            'validity_shamsi' => JalaliDate::format($order->reservation_expires_at ?? now()->addDays(2), 'Y/m/d'),
            'amount_words' => $this->numberToWords((int) round($amountToConvert)),
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
