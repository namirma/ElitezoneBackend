<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش استرداد وجه به سوپلایر {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
    <style>
        .title-main { font-size: 14pt; font-weight: bold; }
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
                <div class="title-main">گزارش استرداد وجه به سوپلایر</div>
                <div class="title-sub">اطلاعیه مابه‌التفاوت پس از باسکول</div>
            </td>
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
            <td colspan="2" class="bg-pink text-center font-bold" style="font-size: 11pt; border-left: 0.75pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-pink text-center font-bold" style="font-size: 11pt;">
                {{ $payment_label ?? 'تسویه شده' }}
            </td>
        </tr>

        {{-- ===== BUYER (partial — 2 rows for refund report) ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات خریدار</td></tr>
        <tr>
            <td class="lbl text-right">نام و نام خانوادگی:</td>
            <td colspan="4" class="val text-right">{{ $buyer['name'] ?? '-' }}</td>
            <td class="lbl text-right">کد ملی:</td>
            <td colspan="3" class="val text-right">{{ enToFa($buyer['national_id'] ?? '-') }}</td>
            <td class="lbl text-right">شماره اقتصادی:</td>
            <td class="val text-right">{{ enToFa($buyer['economic_code'] ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">نشانی:</td>
            <td colspan="4" class="val text-right">{{ $buyer['address'] ?? '-' }}</td>
            <td class="lbl text-right">شماره موبایل:</td>
            <td colspan="5" class="val text-right">{{ enToFa($buyer['phone'] ?? '-') }}</td>
        </tr>

        {{-- ===== SUPPLIER ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات تأمین‌کننده</td></tr>
        <tr>
            <td class="lbl text-right">نام تأمین‌کننده:</td>
            <td colspan="4" class="val text-right">تأمین‌کننده مربوطه</td>
            <td class="lbl text-right">شناسه تأمین‌کننده:</td>
            <td colspan="5" class="val text-right">{{ enToFa($order->items->first()?->supplier_user_id ?? '-') }}</td>
        </tr>

        {{-- ===== SUMMARY ===== --}}
        <tr><td colspan="11" class="section-title">خلاصه مابه‌التفاوت</td></tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">شماره سفارش</td>
            <td colspan="8" class="val text-right">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">وزن اولیه (کیلوگرم)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format($order->weight_before_scale ?? 0)) }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">وزن باسکول (کیلوگرم)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format($order->weight_after_scale ?? 0)) }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">مبلغ کل سفارش (ریال)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format($totals['final'] ?? 0)) }}</td>
        </tr>

        {{-- ===== PAYABLE ===== --}}
        <tr class="bg-gray">
            <td colspan="1" class="text-center font-bold" style="border-left: none; font-size: 9pt; padding: 6px;">مبلغ تسویه:</td>
            <td colspan="7" class="text-center font-bold" style="border-right: none; border-left: none; font-size: 8.5pt; white-space: nowrap;">{{ $amount_words ?? 'صفر' }} ریال</td>
            <td colspan="3" class="text-center font-bold nowrap" style="border-right: none; font-size: 10pt;">{{ enToFa(number_format($totals['final'] ?? 0)) }}</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                مبلغ ما‌به‌التفاوت برای سوپلایر لحاظ گردیده است.
            </td>
        </tr>
    </table>

</body>
</html>
