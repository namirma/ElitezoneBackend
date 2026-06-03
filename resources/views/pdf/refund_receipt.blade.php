<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>رسید استرداد وجه رزرو {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
    <style>
        .bg-refund { background-color: #28a745; color: #fff; }
    </style>
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
            <td colspan="9" rowspan="3" class="title-main text-center bg-white">رسید استرداد وجه رزرو</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ اعتبار :</td>
            <td class="val text-right">{{ enToFa($validity_shamsi ?? $date_shamsi ?? '-') }}</td>
        </tr>

        {{-- ===== STATUS ===== --}}
        <tr>
            <td colspan="2" class="bg-refund text-center font-bold" style="font-size: 11pt; border-left: 1.5pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-refund text-center font-bold" style="font-size: 11pt;">
                استرداد وجه
            </td>
        </tr>

        @include('pdf.partials.buyer_section')

        {{-- ===== SUMMARY ===== --}}
        <tr><td colspan="11" class="section-title">خلاصه استرداد وجه</td></tr>
        @php $reserveAmount = $order->payment_to_reserve ?? $totals['final'] ?? 0; @endphp
        <tr>
            <td colspan="3" class="lbl text-right font-bold">شماره سفارش</td>
            <td colspan="8" class="val text-right">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">مبلغ رزرو پرداخت‌شده (ریال)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format($reserveAmount)) }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">مبلغ استرداد شده (ریال)</td>
            <td colspan="8" class="val text-right amount-cell font-bold">{{ enToFa(number_format($reserveAmount)) }}</td>
        </tr>

        {{-- ===== PAYABLE (refund amount) ===== --}}
        <tr class="bg-gray">
            <td colspan="1" class="text-center font-bold" style="border-left: none; font-size: 9pt; padding: 6px;">مبلغ استرداد شده:</td>
            <td colspan="7" class="text-center font-bold" style="border-right: none; border-left: none; font-size: 8.5pt; white-space: nowrap;">{{ $amount_words ?? 'صفر' }} ریال</td>
            <td colspan="3" class="text-center font-bold nowrap" style="border-right: none; font-size: 10pt;">{{ enToFa(number_format($reserveAmount)) }}</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                مبلغ رزرو فوق پس از لغو سفارش، به کیف پول شما عودت داده شد.
            </td>
        </tr>
    </table>

</body>
</html>
