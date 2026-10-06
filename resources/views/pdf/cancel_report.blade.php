<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش لغو رزرو خریدار {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
    <style>
        .bg-cancelled { background-color: #6c757d; color: #fff; }
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
                <div class="title-main">گزارش لغو رزرو خریدار</div>
                <div class="title-sub">اطلاعیه لغو رزرو برای تأمین‌کننده</div>
            </td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ لغو :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>

        {{-- ===== STATUS ===== --}}
        <tr>
            <td colspan="2" class="bg-cancelled text-center font-bold" style="font-size: 11pt; border-left: 0.75pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-cancelled text-center font-bold" style="font-size: 11pt;">
                لغو شده
            </td>
        </tr>

        {{-- ===== BUYER (partial - 2 rows only for cancel report) ===== --}}
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
            <td class="lbl text-right">استان:</td>
            <td colspan="4" class="val text-right">{{ $buyer['province'] ?? '-' }}</td>
            <td class="lbl text-right">شهر:</td>
            <td colspan="3" class="val text-right">{{ $buyer['city'] ?? '-' }}</td>
            <td class="lbl text-right">کد پستی:</td>
            <td class="val text-right">{{ enToFa($buyer['postal_code'] ?? '-') }}</td>
        </tr>

        {{-- ===== SUPPLIER ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات تأمین‌کننده</td></tr>
        <tr>
            <td class="lbl text-right">نام تأمین‌کننده:</td>
            <td colspan="4" class="val text-right">تأمین‌کننده مربوطه</td>
            <td class="lbl text-right">شناسه تأمین‌کننده:</td>
            <td colspan="5" class="val text-right">{{ enToFa($order->items->first()?->supplier_user_id ?? '-') }}</td>
        </tr>

        {{-- ===== SUMMARY TABLE (no items) ===== --}}
        <tr><td colspan="11" class="section-title">خلاصه اطلاعات لغو رزرو</td></tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">شماره سفارش</td>
            <td colspan="8" class="val text-right">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        {{-- «مبلغ رزرو» سپرده‌ی خریدار است و تأمین‌کننده آن را نمی‌بیند (مدیریت، دورِ پنجم، پرسشِ ۷). --}}
        <tr>
            <td colspan="3" class="lbl text-right font-bold">تاریخ لغو</td>
            <td colspan="8" class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                رزرو فوق توسط خریدار لغو گردیده و مبلغ رزرو به کیف پول خریدار عودت داده شد.
            </td>
        </tr>
    </table>

</body>
</html>
