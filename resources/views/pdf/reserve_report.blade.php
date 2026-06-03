<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش رزرو خریدار برای تأمین‌کننده {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
    <style>
        .title-sub { font-size: 9pt; font-weight: normal; color: #555; }
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
            <td colspan="9" rowspan="3" class="text-center bg-white">
                <div class="title-main">گزارش رزرو خریدار برای تأمین‌کننده</div>
                <div class="title-sub">اطلاعیه رزرو برای تأمین‌کننده</div>
            </td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">اعتبار رزرو تا :</td>
            <td class="val text-right">{{ enToFa($validity_shamsi ?? '-') }}</td>
        </tr>

        {{-- ===== STATUS ===== --}}
        <tr>
            <td colspan="2" class="bg-pink text-center font-bold" style="font-size: 11pt; border-left: 0.75pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-pink text-center font-bold" style="font-size: 11pt;">
                رزرو شده
            </td>
        </tr>

        @include('pdf.partials.buyer_section')

        {{-- ===== SUPPLIER ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات تأمین‌کننده</td></tr>
        <tr>
            <td class="lbl text-right">نام تأمین‌کننده:</td>
            <td colspan="4" class="val text-right">تأمین‌کننده مربوطه</td>
            <td class="lbl text-right">شناسه تأمین‌کننده:</td>
            <td colspan="5" class="val text-right">{{ enToFa($order->items->first()?->supplier_user_id ?? '-') }}</td>
        </tr>

        @include('pdf.partials.items_section')

        {{-- ===== RESERVE EXPIRY ROW ===== --}}
        @php $reserveAmount = $order->payment_to_reserve ?? $totals['final'] ?? 0; @endphp
        <tr class="bg-gray">
            <td colspan="3" class="lbl text-right font-bold" style="font-size: 8.5pt;">اعتبار رزرو تا:</td>
            <td colspan="8" class="val text-right font-bold" style="font-size: 8.5pt;">{{ enToFa($validity_shamsi ?? '-') }}</td>
        </tr>

        {{-- ===== PAYABLE (reserve amount) ===== --}}
        <tr class="bg-gray">
            <td colspan="1" class="text-center font-bold" style="border-left: none; font-size: 9pt; padding: 6px;">مبلغ رزرو:</td>
            <td colspan="7" class="text-center font-bold" style="border-right: none; border-left: none; font-size: 8.5pt; white-space: nowrap;">{{ $amount_words ?? 'صفر' }} ریال</td>
            <td colspan="3" class="text-center font-bold nowrap" style="border-right: none; font-size: 10pt;">{{ enToFa(number_format($reserveAmount)) }}</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                این رزرو توسط خریدار ثبت شده است. در صورت تکمیل پرداخت توسط خریدار، دستور بارگیری صادر خواهد شد.
            </td>
        </tr>
    </table>

</body>
</html>
