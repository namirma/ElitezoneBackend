<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش پرداخت حق مشاوره {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
    <style>
        /* Summary table for doc D */
        table.summary-grid {
            width: 100%;
            border-collapse: collapse;
            border: 1.5pt solid #000;
            table-layout: fixed;
            margin-top: 0;
        }
        table.summary-grid td {
            border: 0.75pt solid #000;
            vertical-align: middle;
            padding: 6px 8px;
        }
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
            <td colspan="9" rowspan="3" class="title-main text-center bg-white">گزارش پرداخت حق مشاوره</td>
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
                {{ $payment_label ?? 'پرداخت شده' }}
            </td>
        </tr>

        @include('pdf.partials.buyer_section')

        {{-- ===== SUMMARY TABLE (Doc D — no items table) ===== --}}
        <tr><td colspan="11" class="section-title">خلاصه سفارش و حق مشاوره</td></tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">شماره سفارش</td>
            <td colspan="8" class="val text-right">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">مبلغ سفارش (ریال)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format($totals['final'] ?? 0)) }}</td>
        </tr>
        <tr>
            <td colspan="3" class="lbl text-right font-bold">حق مشاوره تخمینی (ریال)</td>
            <td colspan="8" class="val text-right amount-cell">{{ enToFa(number_format((int)round(($totals['final'] ?? 0) * 0.006))) }}</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                حق مشاوره پس از تأیید فاکتور سامانه مودیان از حالت هولد خارج و به کیف پول مشاور فنی واریز می‌گردد.
            </td>
        </tr>
    </table>

</body>
</html>
