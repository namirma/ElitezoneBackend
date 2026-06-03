<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش استرداد وجه به سوپلایر {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    <style>
        /* ============================================================
           mPDF Bulletproof Stylesheet - 11-Column Mathematical Grid
           ============================================================ */
        * { box-sizing: border-box; }

        body {
            font-family: yekanbakh, "Yekan Bakh FaNum", Tahoma, sans-serif;
            direction: rtl;
            color: #000;
            margin: 0;
            padding: 0;
            font-size: 7.5pt;
        }

        /* 1. Brand Logos */
        table.brand { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.brand td { border: none; padding: 0 2px; vertical-align: middle; }
        td.brand-web  { text-align: right; }
        td.brand-logo { text-align: left; }

        /* 2. Master Grid */
        table.master-grid {
            width: 100%;
            border-collapse: collapse;
            border: 1.5pt solid #000;
            table-layout: fixed;
        }
        table.master-grid td, table.master-grid th {
            border: 0.75pt solid #000;
            vertical-align: middle;
            padding: 3.5px 3px;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .nowrap { white-space: nowrap; overflow: hidden; }

        .bg-gray { background-color: #E9ECEF; }
        .bg-pink { background-color: #D60A69; color: #ffffff; }
        .bg-white { background-color: #ffffff; }
        .text-red { color: #FF0000; }
        .text-muted { color: #A6A6A6; }

        .title-main { font-size: 14pt; font-weight: bold; }
        .title-sub { font-size: 9pt; font-weight: normal; color: #555; }
        .section-title { font-size: 10pt; font-weight: bold; text-align: center; background-color: #E9ECEF; padding: 4.5px 0; }

        .lbl { font-size: 7.5pt; font-weight: normal; background-color: #fcfcfc; }
        .val { font-size: 7.5pt; }

        .items-head td { text-align: center; font-size: 7.5pt; font-weight: normal; padding: 2px; }

        .amount-cell { font-size: 7.5pt; white-space: nowrap; text-align: center; }

        .notes-text { line-height: 1.6; font-size: 7.5pt; padding: 6px 10px !important; }
    </style>
</head>
<body>

    {{-- ================= 1. BRAND (LOGOS) ================= --}}
    <table class="brand">
        <tr>
            <td class="brand-web" style="width:50%;">
                @if(!empty($brand_web))<img src="{{ $brand_web }}" alt="www.elitezone.me" width="200" height="37" style="width:49mm;height:9mm;">@endif
            </td>
            <td class="brand-logo" style="width:50%;">
                @if(!empty($brand_logo))<img src="{{ $brand_logo }}" alt="ELITEZONE" width="217" height="40" style="width:53mm;height:9.9mm;">@endif
            </td>
        </tr>
    </table>

    {{-- ================= 2. THE MASTER GRID ================= --}}
    <table class="master-grid">
        <colgroup>
            <col style="width: 10.01%;"> <col style="width: 22.39%;"> <col style="width: 4.84%;">  <col style="width: 6.15%;">  <col style="width: 5.32%;">  <col style="width: 8.07%;">  <col style="width: 8.07%;">  <col style="width: 8.07%;">  <col style="width: 8.07%;">  <col style="width: 8.07%;">  <col style="width: 10.94%;"> </colgroup>

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
            <td colspan="2" class="bg-pink text-center font-bold" style="font-size: 11pt; border-left: 1.5pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-pink text-center font-bold" style="font-size: 11pt;">
                {{ $payment_label ?? 'تسویه شده' }}
            </td>
        </tr>

        {{-- ===== BUYER ===== --}}
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
