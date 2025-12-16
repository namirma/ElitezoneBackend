<?php

namespace App\Services\Invoice;

use App\Models\Order;
use App\Traits\ConvertNumberToWord;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;

class InvoiceGenerator
{
    use ConvertNumberToWord;

    public function generate(Order $order, string $type)
    {
        // بارگذاری روابط مورد نیاز
        $order->load(['items.productVariant', 'placer.profile', 'address.city', 'address.province']);

        // تنظیمات فروشنده (می‌تواند از دیتابیس یا کانفیگ خوانده شود)
        $seller = [
            'name'          => 'شرکت نرم‌افزاری سازه اسپادانا',
            'economic_code' => '411347688653',
            'reg_id'        => '31106',
            'national_id'   => '10260516722',
            'address'       => 'اصفهان، پارک علم و فناوری، ساختمان شهرک علمی تحقیقاتی، واحد 114',
            'phone'         => '03134005000',
            'postal_code'   => '8158184500',
        ];

        // تنظیمات خریدار
        $buyerProfile = $order->placer->profile;
        $buyer = [
            'name'          => ($buyerProfile->first_name ?? '') . ' ' . ($buyerProfile->last_name ?? ''),
            'economic_code' => $buyerProfile->economic_code ?? '-',
            'national_id'   => $order->placer->national_code ?? '-',
            'reg_id'        => null,
            'postal_code'   => $order->address->postal_code ?? '-',
            'phone'         => $order->placer->mobile,
            'address'       => $order->address->postal_address ?? $order->address->address ?? '-',
        ];

        $amountInWords = $this->numberToWords($order->final_amount);

        $data = [
            'order'         => $order,
            'seller'        => $seller,
            'buyer'         => $buyer,
            'type'          => $type, // initial, final, tax
            'date'          => verta($order->created_at)->format('Y/m/d'),
            'validity_date' => verta($order->created_at)->addDay()->format('Y/m/d'),
            'amount_words'  => $amountInWords,
        ];

        // بازگشت آبجکت PDF
        return PDF::loadView('pdf.invoice', $data, [], [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
            'default_font'  => 'yekan'
        ]);
    }
}
