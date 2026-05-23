<?php

namespace App\Services\Invoice;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserProfile;
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
     * اطلاعات ثابت فروشنده (گروه نرم‌افزاری سازه)
     */
    private const SELLER_INFO = [
        'name'          => 'شرکت نرم‌افزاری سازه اسپادانا',
        'economic_code' => '411347688653',
        'reg_id'        => '31106',
        'national_id'   => '10260516722',
        'address'       => 'اصفهان، پارک علم و فناوری، ساختمان شهرک علمی تحقیقاتی، واحد 114',
        'phone'         => '03134005000',
        'postal_code'   => '8158184500',
    ];

    /**
     * آرایه کامل داده مورد نیاز Blade view را برمی‌گرداند.
     *
     * پیش‌نیاز: $order باید relations زیر را load کرده باشد:
     *   items.productVariant.product, placer.profile, address.city, address.province
     */
    public function build(Order $order, string $docType, string $invoiceNumber): array
    {
        $buyerProfile = $order->placer?->profile;
        $lineItems    = $this->buildLineItems($order);
        $totals       = $this->buildTotals($order, $lineItems);

        $amountToConvert = match ($docType) {
            FinancialCodes::DOC_RESERVE_RECEIPT,
            FinancialCodes::DOC_RESERVE_REPORT => (float) $order->payment_to_reserve,
            default                             => (float) $order->final_amount,
        };

        return [
            'invoice_number'       => $invoiceNumber,
            'order'                => $order,
            'seller'               => self::SELLER_INFO,
            'buyer'                => [
                'name'          => $this->resolveBuyerName($buyerProfile),
                'economic_code' => $buyerProfile?->economic_code ?? '-',
                'national_id'   => $buyerProfile?->national_code
                    ?? $buyerProfile?->national_id
                    ?? $order->placer?->national_code
                    ?? '-',
                'phone'       => $buyerProfile?->phone ?? $order->placer?->mobile ?? '-',
                'postal_code' => $order->address?->postal_code ?? '-',
                'address'     => $order->address?->postal_address
                    ?? $order->address?->address
                    ?? '-',
                'province' => $order->address?->province?->name ?? '',
                'city'     => $order->address?->city?->name ?? '',
            ],
            'line_items'           => $lineItems,
            'totals'               => $totals,
            'doc_type'             => $docType,
            'date_shamsi'          => verta()->format('Y/m/d'),
            'amount_words'         => $this->numberToWords((int) round($amountToConvert)),
            'payment_label'        => match ($order->payment_status) {
                Order::PAYMENT_PAID    => 'پرداخت شده',
                Order::PAYMENT_FAILED  => 'ناموفق',
                Order::PAYMENT_PENDING => 'پرداخت نشده',
                default                => 'پرداخت نشده',
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

        $sumGross      = array_sum(array_column($lines, 'gross'));
        $orderDiscount = (float) $order->discount_amount;
        $orderVat      = (float) $order->vat_amount;

        foreach ($lines as &$line) {
            $ratio              = $sumGross > 0
                ? ($line['gross'] / $sumGross)
                : (1 / count($lines));
            $line['discount']       = (int) round($orderDiscount * $ratio);
            $line['after_discount'] = max(0, (int) round($line['gross'] - $line['discount']));
            $line['tax']            = (int) round($orderVat * $ratio);
            $line['final']          = $line['after_discount'] + $line['tax'];
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
        $vat      = (float) $order->vat_amount;
        $final    = (float) $order->final_amount;

        if ($lineItems !== []) {
            return [
                'quantity'       => array_sum(array_column($lineItems, 'quantity')),
                'gross'          => $subtotal > 0 ? $subtotal : array_sum(array_column($lineItems, 'gross')),
                'discount'       => $discount,
                'after_discount' => max(0, $subtotal - $discount),
                'tax'            => $vat,
                'final'          => $final > 0 ? $final : array_sum(array_column($lineItems, 'final')),
            ];
        }

        return [
            'quantity'       => 0,
            'gross'          => $subtotal,
            'discount'       => $discount,
            'after_discount' => max(0, $subtotal - $discount),
            'tax'            => $vat,
            'final'          => $final,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeLineItem(OrderItem $item, int $row): array
    {
        $unitPrice  = (float) $item->unit_price;
        $totalPrice = (float) $item->total_price;
        $weight     = (float) ($item->weight ?? 0);
        $qty        = (float) ($item->quantity ?? 0);
        $unitName   = $item->unit_name ?: 'عدد';

        if ($weight > 0) {
            $displayQty  = $weight;
            $displayUnit = 'کیلوگرم';
        } elseif ($qty > 0) {
            $displayQty  = $qty;
            $displayUnit = $unitName;
        } else {
            $displayQty  = $this->sumQuantityDetails($item->quantity_details);
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
            'row'        => $row,
            'name'       => $name,
            'quantity'   => $displayQty,
            'unit'       => $displayUnit,
            'unit_price' => $unitPrice,
            'gross'      => (int) round($gross),
            'note'       => '',
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
