<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>رسید صورت‌حساب متمم {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
</head>
<body>

    @include('pdf.partials.brand')

    {{-- ================= 2. THE MASTER GRID ================= --}}
    <table class="master-grid">
        @include('pdf.partials.colgroup')

        {{-- ===== HEADER ===== --}}
        <tr>
            <td class="lbl text-right">شماره سفارش :</td>
            <td class="val text-right font-bold">{{ enToFa($invoice_number ?? ($order->order_number ?? '-')) }}</td>
            <td colspan="9" rowspan="4" class="title-main text-center bg-white">رسید صورت‌حساب متمم</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">وزن اولیه :</td>
            <td class="val text-right">{{ enToFa(number_format($order->weight_before_scale ?? 0)) }} کیلوگرم</td>
        </tr>
        <tr>
            <td class="lbl text-right">وزن باسکول :</td>
            <td class="val text-right">{{ enToFa(number_format($order->weight_after_scale ?? 0)) }} کیلوگرم</td>
        </tr>

        {{-- ===== STATUS ===== --}}
        <tr>
            <td colspan="2" class="bg-pink text-center font-bold" style="font-size: 11pt; border-left: 1.5pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-pink text-center font-bold" style="font-size: 11pt;">
                {{ $payment_label ?? 'پرداخت شده' }}
            </td>
        </tr>

        @include('pdf.partials.buyer_section')
        @include('pdf.partials.seller_section')
        @include('pdf.partials.items_section')
        @include('pdf.partials.payable_row')

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                این رسید صورت‌حساب متمم پس از باسکول صادر گردیده است. مابه‌التفاوت از طریق کیف پول تسویه شده است.
            </td>
        </tr>
    </table>

</body>
</html>
