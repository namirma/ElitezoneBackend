<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تأییدیه فاکتور مودیان مشاور فنی {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
    <style>
        .title-main { font-size: 13pt; font-weight: bold; }
    </style>
</head>
<body>

    @include('pdf.partials.brand')

    {{-- ================= 2. THE MASTER GRID ================= --}}
    <table class="master-grid">
        @include('pdf.partials.colgroup')

        {{-- ===== HEADER ===== --}}
        <tr>
            <td class="lbl text-right">شماره سند :</td>
            <td class="val text-right font-bold">{{ enToFa($invoice_number ?? '-') }}</td>
            <td colspan="9" rowspan="3" class="title-main text-center bg-white">تأییدیه فاکتور مودیان مشاور فنی</td>
        </tr>
        <tr>
            <td class="lbl text-right">شماره سفارش :</td>
            <td class="val text-right font-bold">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>

        {{-- ===== STATUS ===== --}}
        <tr>
            <td colspan="2" class="bg-pink text-center font-bold" style="font-size: 11pt; border-left: 0.75pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-pink text-center font-bold" style="font-size: 11pt;">
                ثبت شده در سامانه مودیان
            </td>
        </tr>

        {{-- ===== CONFIRMATION DETAILS ===== --}}
        <tr><td colspan="11" class="section-title">اطلاعات فاکتور مودیان مشاور فنی</td></tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">شماره سند</td>
            <td colspan="8" class="val text-right">{{ enToFa($invoice_number ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">شماره سفارش</td>
            <td colspan="8" class="val text-right">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">صادرکننده</td>
            <td colspan="8" class="val text-right">مشاور فنی</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">گیرنده</td>
            <td colspan="8" class="val text-right">شرکت نرم‌افزاری سازه اسپادانا</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">وضعیت ثبت</td>
            <td colspan="8" class="val text-right font-bold">ثبت شده در سامانه مودیان</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">تاریخ ثبت</td>
            <td colspan="8" class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">مبلغ حق مشاوره (ریال)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format((int)round(($totals['final'] ?? 0) * 0.006))) }}</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                این تأییدیه برای فاکتور صادر شده توسط مشاور فنی در سامانه مودیان است. پس از تأیید، حق مشاوره از حالت هولد خارج می‌گردد.
            </td>
        </tr>
    </table>

</body>
</html>
