<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش صورت‌حساب متمم سوپلایر {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
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
            <td colspan="9" rowspan="4" class="text-center bg-white">
                <div class="title-main">گزارش صورت‌حساب متمم سوپلایر</div>
                <div class="title-sub">اطلاعیه پس از باسکول</div>
            </td>
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

        {{-- ===== BUYER (partial — 2 rows for supplier report) ===== --}}
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

        @include('pdf.partials.items_section')
        @include('pdf.partials.payable_row')

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                این گزارش صورت‌حساب متمم پس از باسکول برای تأمین‌کننده صادر گردیده است. مابه‌التفاوت از طریق کیف پول تسویه شده است.
            </td>
        </tr>
    </table>

</body>
</html>
