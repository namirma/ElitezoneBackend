<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>پیش فاکتور فروش {{ $invoice_number ?? $order->order_number }}</title>
    <style>
        /* ============================================================
           FONT — yekanbakh از طریق config/pdf.php لود می‌شود
           (mPDF با @font-face درست کار نمی‌کند، فقط با font_data در config)
           ============================================================ */
        body {
            font-family: yekanbakh, sans-serif;
            font-size: 9pt;
            color: #000;
            direction: rtl;
            margin: 0;
            padding: 0;
        }
        table, td, th, div, span {
            font-family: yekanbakh, sans-serif;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            table-layout: fixed;
        }

        /* ============================================================
           1) BRAND BAR
           ============================================================ */
        table.brand td {
            border: none;
            padding: 0 4px 6px;
            vertical-align: middle;
        }
        .brand-left {
            text-align: left;
            font-size: 26pt;
            font-weight: bold;
            color: #c8c8c8;
            letter-spacing: 1px;
            line-height: 1;
        }
        .brand-icon {
            background-color: #D60A69;
            color: #fff;
            padding: 1px 8px 4px 8px;
            font-size: 16pt;
            font-weight: bold;
        }
        .brand-right {
            text-align: right;
            color: #888;
            font-size: 10pt;
            letter-spacing: 5px;
            vertical-align: bottom;
            padding-bottom: 8px;
        }

        /* ============================================================
           2) HEADER — سربرگ (طبق merge اکسل: B-C برای label/value و D-L برای عنوان)
           ============================================================ */
        table.header td {
            border: 1px solid #000;
            padding: 6px 10px;
            vertical-align: middle;
            font-size: 10pt;
        }
        .h-lbl {
            font-weight: bold;
            text-align: right;
            background: #fff;
        }
        .h-val {
            text-align: right;
            font-weight: bold;
            background: #fff;
        }
        .h-title {
            text-align: center;
            font-size: 22pt;
            font-weight: bold;
            background: #fff;
            line-height: 1;
        }

        /* ============================================================
           3) STATUS BAR
           ============================================================ */
        table.status td {
            background-color: #D60A69;
            color: #ffffff;
            text-align: center;
            font-weight: bold;
            font-size: 13pt;
            padding: 10px 10px;
            border: 1px solid #000;
        }

        /* ============================================================
           4) SECTION TITLE — نوار خاکستری
           ============================================================ */
        .section {
            background-color: #E9ECEF;
            border: 1px solid #000;
            padding: 8px 8px;
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
            margin-top: 6px;
        }

        /* ============================================================
           5) PARTY TABLE — اطلاعات خریدار/فروشنده
           ============================================================ */
        table.party td {
            border: 1px solid #000;
            padding: 6px 8px;
            vertical-align: middle;
            font-size: 9pt;
            text-align: right;
            background: #fff;
        }
        table.party td.lbl {
            font-weight: bold;
        }

        /* ============================================================
           6) ITEMS TABLE — جدول اقلام
           ============================================================ */
        table.items th,
        table.items td {
            border: 1px solid #000;
            padding: 5px 3px;
            text-align: center;
            font-size: 8.5pt;
            vertical-align: middle;
        }
        table.items thead th {
            background-color: #E9ECEF;
            font-weight: bold;
            padding: 6px 3px;
            line-height: 1.25;
            font-size: 8.5pt;
        }
        table.items td.desc {
            text-align: right;
            font-size: 8.5pt;
            padding: 5px 6px;
        }
        table.items tfoot td {
            background-color: #E9ECEF;
            font-weight: bold;
            padding: 7px 3px;
            font-size: 9pt;
        }

        /* ============================================================
           7) PAYABLE — مبلغ قابل پرداخت
           ============================================================ */
        table.payable td {
            border: 1px solid #000;
            padding: 9px 10px;
            font-size: 10pt;
            vertical-align: middle;
            background: #fff;
        }
        table.payable td.lbl {
            font-weight: bold;
            text-align: right;
        }
        table.payable td.words {
            text-align: right;
            font-weight: bold;
        }
        table.payable td.amount {
            font-weight: bold;
            text-align: center;
            font-size: 11pt;
        }

        /* ============================================================
           8) NOTES — توضیحات مهم
           ============================================================ */
        table.notes td {
            border: 1px solid #000;
            padding: 8px 10px;
            vertical-align: middle;
            background: #fff;
        }
        table.notes td.lbl {
            font-weight: bold;
            text-align: center;
            font-size: 11pt;
        }
        table.notes td.body {
            text-align: justify;
            font-size: 9pt;
            line-height: 1.9;
            color: #000;
        }
    </style>
</head>
<body>

    {{-- ============================================================
         تعریف عرض ستون‌ها (دقیقاً مطابق اکسل / 11 ستون B..L)
         B=10.01  C=22.39  D=4.84  E=6.15  F=5.32  G..K=8.07 ×5  L=10.94
         ============================================================ --}}

    {{-- 1) BRAND BAR --}}
    <table class="brand" cellpadding="0" cellspacing="0">
        <tr>
            <td class="brand-left" style="width:50%;">
                ELITEZONE<span class="brand-icon">e</span>
            </td>
            <td class="brand-right" style="width:50%;">
                w w w . e l i t e z o n e . m e
            </td>
        </tr>
    </table>

    {{-- 2) HEADER — سربرگ سفارش
         B = label (10.01%), C = value (22.39%), D-L = عنوان (67.60%) --}}
    <table class="header" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:10.01%;">
            <col style="width:22.39%;">
            <col style="width:67.60%;">
        </colgroup>
        <tr>
            <td class="h-lbl">شماره سفارش :</td>
            <td class="h-val">{{ $invoice_number ?? $order->order_number }}</td>
            <td rowspan="3" class="h-title">پیش فاکتور فروش</td>
        </tr>
        <tr>
            <td class="h-lbl" style="border-top:none;">تاریخ صدور :</td>
            <td class="h-val" style="border-top:none;">{{ $date_shamsi }}</td>
        </tr>
        <tr>
            <td class="h-lbl" style="border-top:none;">تاریخ اعتبار :</td>
            <td class="h-val" style="border-top:none;">{{ $date_shamsi }}</td>
        </tr>
    </table>

    {{-- 3) STATUS BAR — B+C = وضعیت (32.40%), D-L = وضعیت متن (67.60%) --}}
    <table class="status" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:32.40%;">
            <col style="width:67.60%;">
        </colgroup>
        <tr>
            <td>وضعیت</td>
            <td>{{ $payment_label ?? 'پرداخت نشده' }}</td>
        </tr>
    </table>

    {{-- 4) BUYER --}}
    <div class="section">مشخصات خریدار</div>
    <table class="party" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:10.01%;"> {{-- B label1 --}}
            <col style="width:22.39%;"> {{-- C    --}}
            <col style="width:4.84%;">  {{-- D    value1 (C-F merged) --}}
            <col style="width:6.15%;">  {{-- E    --}}
            <col style="width:5.32%;">  {{-- F    --}}
            <col style="width:8.07%;">  {{-- G label2 --}}
            <col style="width:8.07%;">  {{-- H    --}}
            <col style="width:8.07%;">  {{-- I    value2 (H-J merged) --}}
            <col style="width:8.07%;">  {{-- J    --}}
            <col style="width:8.07%;">  {{-- K label3 --}}
            <col style="width:10.94%;"> {{-- L    value3 --}}
        </colgroup>
        <tr>
            <td class="lbl">نام و نام خانوادگی :</td>
            <td colspan="4">{{ $buyer['name'] ?? '' }}</td>
            <td class="lbl">کد ملی :</td>
            <td colspan="3">{{ $buyer['national_id'] ?? '' }}</td>
            <td class="lbl">شماره اقتصادی :</td>
            <td>{{ $buyer['economic_code'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="lbl">استان :</td>
            <td colspan="4">{{ $buyer['province'] ?? '' }}</td>
            <td class="lbl">شهر :</td>
            <td colspan="3">{{ $buyer['city'] ?? '' }}</td>
            <td class="lbl">کد پستی :</td>
            <td>{{ $buyer['postal_code'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="lbl">نشانی :</td>
            <td colspan="4">{{ $buyer['address'] ?? '' }}</td>
            <td class="lbl">شماره موبایل :</td>
            <td colspan="5">{{ $buyer['phone'] ?? '' }}</td>
        </tr>
    </table>

    {{-- 5) SELLER --}}
    <div class="section">مشخصات فروشنده</div>
    <table class="party" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:10.01%;">
            <col style="width:22.39%;">
            <col style="width:4.84%;">
            <col style="width:6.15%;">
            <col style="width:5.32%;">
            <col style="width:8.07%;">
            <col style="width:8.07%;">
            <col style="width:8.07%;">
            <col style="width:8.07%;">
            <col style="width:8.07%;">
            <col style="width:10.94%;">
        </colgroup>
        <tr>
            <td class="lbl">نام شخص حقیقی/حقوقی :</td>
            <td colspan="4">{{ $seller['name'] }}</td>
            <td class="lbl">شماره اقتصادی :</td>
            <td colspan="3">{{ $seller['economic_code'] }}</td>
            <td class="lbl">شماره ثبت :</td>
            <td>{{ $seller['reg_id'] }}</td>
        </tr>
        <tr>
            <td class="lbl">شهر/استان :</td>
            <td colspan="4">{{ $seller['city'] ?? 'اصفهان/اصفهان' }}</td>
            <td class="lbl">کد پستی :</td>
            <td colspan="3">{{ $seller['postal_code'] }}</td>
            <td class="lbl">شناسه ملی :</td>
            <td>{{ $seller['national_id'] }}</td>
        </tr>
        <tr>
            <td class="lbl">نشانی :</td>
            <td colspan="4">{{ $seller['address'] }}</td>
            <td class="lbl">شماره تلفن :</td>
            <td colspan="3">{{ $seller['phone'] }}</td>
            <td class="lbl">شماره نمابر :</td>
            <td>{{ $seller['fax'] ?? $seller['phone'] }}</td>
        </tr>
    </table>

    {{-- 6) ITEMS --}}
    <div class="section">مشخصات کالا یا خدمات مورد معامله</div>
    <table class="items" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:10.01%;"> {{-- B ردیف --}}
            <col style="width:22.39%;"> {{-- C شرح --}}
            <col style="width:4.84%;">  {{-- D مقدار part1 --}}
            <col style="width:6.15%;">  {{-- E مقدار part2 --}}
            <col style="width:5.32%;">  {{-- F مبلغ واحد --}}
            <col style="width:8.07%;">  {{-- G مبلغ --}}
            <col style="width:8.07%;">  {{-- H تخفیف --}}
            <col style="width:8.07%;">  {{-- I پس از تخفیف --}}
            <col style="width:8.07%;">  {{-- J مالیات --}}
            <col style="width:8.07%;">  {{-- K قابل پرداخت --}}
            <col style="width:10.94%;"> {{-- L توضیحات --}}
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">ردیف</th>
                <th rowspan="2">شرح کالا یا خدمات</th>
                <th colspan="2">واحد(کیلوگرم)</th>
                <th colspan="6">واحد مبالغ (ریال)</th>
                <th rowspan="2">توضیحات کالا</th>
            </tr>
            <tr>
                <th colspan="2">مقدار</th>
                <th>مبلغ واحد</th>
                <th>مبلغ</th>
                <th>تخفیف</th>
                <th>مبلغ پس از تخفیف</th>
                <th>جمع مالیات</th>
                <th>مبلغ قابل پرداخت</th>
            </tr>
        </thead>
        <tbody>
            @php
                $sumQty=$sumGross=$sumDiscount=$sumAfterDisc=$sumTax=$sumFinal=0;
            @endphp
            @foreach($order->items as $index => $item)
                @php
                    $qty       = (float) ($item->quantity ?? $item->weight ?? 0);
                    $unitPrice = (float) ($item->unit_price ?? $item->price ?? 0);
                    $gross     = (float) ($item->total_price ?? ($qty * $unitPrice));
                    $discount  = (float) ($item->discount_amount ?? 0);
                    $afterDisc = max(0, $gross - $discount);
                    $tax       = (float) ($item->tax_amount ?? $item->vat_amount ?? 0);
                    $final     = $afterDisc + $tax;
                    $sumQty       += $qty;
                    $sumGross     += $gross;
                    $sumDiscount  += $discount;
                    $sumAfterDisc += $afterDisc;
                    $sumTax       += $tax;
                    $sumFinal     += $final;
                    $name = $item->product_name
                            ?? optional($item->productVariant)->name
                            ?? optional(optional($item->productVariant)->product)->name
                            ?? 'محصول';
                    $itemNote = $item->note ?? $item->description ?? '';
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="desc">{{ $name }}</td>
                    <td colspan="2">{{ number_format($qty, $qty == floor($qty) ? 0 : 2) }}</td>
                    <td>{{ number_format($unitPrice) }}</td>
                    <td>{{ number_format($gross) }}</td>
                    <td>{{ number_format($discount) }}</td>
                    <td>{{ number_format($afterDisc) }}</td>
                    <td>{{ number_format($tax) }}</td>
                    <td>{{ number_format($final) }}</td>
                    <td>{{ $itemNote }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">جمع کل :</td>
                <td colspan="2">{{ number_format($sumQty, $sumQty == floor($sumQty) ? 0 : 2) }}</td>
                <td>-</td>
                <td>{{ number_format($sumGross) }}</td>
                <td>{{ number_format($sumDiscount) }}</td>
                <td>{{ number_format($sumAfterDisc) }}</td>
                <td>{{ number_format($sumTax) }}</td>
                <td>{{ number_format($sumFinal) }}</td>
                <td>-</td>
            </tr>
        </tfoot>
    </table>

    {{-- 7) PAYABLE — B=10.01% (label), C-G=46.77% (words), H-L=43.22% (amount) --}}
    <table class="payable" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:10.01%;">
            <col style="width:46.77%;">
            <col style="width:43.22%;">
        </colgroup>
        <tr>
            <td class="lbl">مبلغ قابل پرداخت :</td>
            <td class="words">{{ $amount_words }} ریال</td>
            <td class="amount">{{ number_format($sumFinal) }}</td>
        </tr>
    </table>

    {{-- 8) NOTES — B=10.01% (label), C-L=89.99% (body) --}}
    <table class="notes" cellpadding="0" cellspacing="0">
        <colgroup>
            <col style="width:10.01%;">
            <col style="width:89.99%;">
        </colgroup>
        <tr>
            <td class="lbl">توضیحات مهم</td>
            <td class="body">
                @if(!empty($order->notes))
                    {{ $order->notes }}
                @else
                    مهلت واریز مبلغ پیش فاکتور تا ساعت ۱۰:۰۰ صبح روز چهارشنبه می‌باشد. در صورتی که وجه پیش فاکتور قبل از این مهلت پرداخت نگردد پیش فاکتور صادره منقضی شده و از درجه اعتبار ساقط می‌گردد و خریدار حق هیچگونه مطالبه ای ندارد.<br>
                    زمان تحویل محصولات فوق طبق برنامه تولید مجتمع فولاد کویر می‌باشد.<br>
                    محصولات فوق با نشان کویر عرضه می گردد.<br>
                    بعد از صدور فاکتور چنانچه وزن ارسالی با وزن پیش فاکتور متفاوت بود، مابه التفاوت حساب از طریق کیف پول تسویه می شود.
                @endif
            </td>
        </tr>
    </table>

</body>
</html>
