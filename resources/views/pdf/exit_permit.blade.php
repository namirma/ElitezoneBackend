<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مجوز خروج و حواله {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
</head>
<body>

    @include('pdf.partials.brand')

    {{-- ================= 2. THE MASTER GRID ================= --}}
    <table class="master-grid">
        @include('pdf.partials.colgroup')

        {{-- ===== HEADER ===== --}}
        <tr>
            <td class="lbl text-right">شماره مجوز :</td>
            <td class="val text-right font-bold">{{ enToFa($invoice_number ?? '-') }}</td>
            <td colspan="9" rowspan="4" class="title-main text-center bg-white">مجوز خروج و حواله</td>
        </tr>
        <tr>
            <td class="lbl text-right">شماره سفارش :</td>
            <td class="val text-right font-bold">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">وزن باسکول :</td>
            <td class="val text-right font-bold">{{ enToFa(number_format($order->weight_after_scale ?? 0)) }} کیلوگرم</td>
        </tr>

        {{-- ===== STATUS ===== --}}
        <tr>
            <td colspan="2" class="bg-pink text-center font-bold" style="font-size: 11pt; border-left: 1.5pt solid #fff;">وضعیت</td>
            <td colspan="9" class="bg-pink text-center font-bold" style="font-size: 11pt;">
                مجوز صادر شده
            </td>
        </tr>

        @include('pdf.partials.buyer_section')

        {{-- ===== ITEMS HEADER (minimal: product name + weight) ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات کالای مجاز برای خروج</td></tr>
        <tr class="items-head bg-white">
            <td style="border-bottom: 1.5pt solid #000;">ردیف</td>
            <td colspan="3" style="border-bottom: 1.5pt solid #000;">شرح کالا</td>
            <td colspan="3" style="border-bottom: 1.5pt solid #000;">وزن سفارش (کیلوگرم)</td>
            <td colspan="4" style="border-bottom: 1.5pt solid #000;">وزن باسکول (کیلوگرم)</td>
        </tr>

        {{-- ===== ITEMS LOOP (minimal) ===== --}}
        @if(isset($line_items) && count($line_items) > 0)
            @foreach($line_items as $line)
                @php $q = (float) ($line['quantity'] ?? 0); $qf = number_format($q, $q == floor($q) ? 0 : 2); @endphp
                <tr>
                    <td class="amount-cell">{{ enToFa($line['row'] ?? $loop->iteration) }}</td>
                    <td colspan="3" class="text-right val">{{ $line['name'] ?? '-' }}</td>
                    <td colspan="3" class="amount-cell">{{ enToFa($qf) }}</td>
                    <td colspan="4" class="amount-cell font-bold">{{ enToFa(number_format($order->weight_after_scale ?? 0)) }}</td>
                </tr>
            @endforeach
        @else
            <tr><td colspan="11" class="text-center">اقلامی یافت نشد.</td></tr>
        @endif

        {{-- ===== TOTALS ROW ===== --}}
        @php
            $tq = (float) ($totals['quantity'] ?? 0);
            $tqf = number_format($tq, $tq == floor($tq) ? 0 : 2);
        @endphp
        <tr>
            <td colspan="4" class="text-center font-bold" style="font-size: 9pt;">جمع وزن سفارش :</td>
            <td colspan="3" class="amount-cell font-bold">{{ enToFa($tqf) }} کیلوگرم</td>
            <td colspan="4" class="amount-cell font-bold">{{ enToFa(number_format($order->weight_after_scale ?? 0)) }} کیلوگرم</td>
        </tr>

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                این مجوز اجازه خروج کالای فوق از محوطه کارخانه را صادر می‌نماید.
            </td>
        </tr>
    </table>

</body>
</html>
