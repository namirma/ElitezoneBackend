        {{-- ===== ITEMS HEADER ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات کالا یا خدمات مورد معامله</td></tr>
        {{-- رنگ سفید شد و عرض‌ها کم شد --}}
        <tr class="items-head bg-white">
            <td rowspan="2" style="border-bottom: 1.5pt solid #000;">ردیف</td>
            <td rowspan="2" style="border-bottom: 1.5pt solid #000;">شرح کالا یا خدمات</td>
            <td colspan="2" class="text-muted" style="border-bottom: none;">واحد(کیلوگرم)</td>
            <td colspan="6" class="text-muted" style="border-bottom: none;">واحد مبالغ (ریال)</td>
            <td rowspan="2" style="border-bottom: 1.5pt solid #000;">توضیحات کالا</td>
        </tr>
        <tr class="items-head bg-white">
            <td colspan="2" style="border-top: none; border-bottom: 1.5pt solid #000;">مقدار</td>
            <td style="border-top: none; border-bottom: 1.5pt solid #000;">مبلغ واحد</td>
            <td style="border-top: none; border-bottom: 1.5pt solid #000;">مبلغ</td>
            <td class="text-red" style="border-top: none; border-bottom: 1.5pt solid #000;">تخفیف</td>
            <td style="border-top: none; border-bottom: 1.5pt solid #000;">پس از تخفیف</td>
            <td style="border-top: none; border-bottom: 1.5pt solid #000;">جمع مالیات</td>
            <td style="border-top: none; border-bottom: 1.5pt solid #000;">مبلغ قابل پرداخت</td>
        </tr>

        {{-- ===== ITEMS LOOP ===== --}}
        @if(isset($line_items) && count($line_items) > 0)
            @foreach($line_items as $line)
                @php $q = (float) ($line['quantity'] ?? 0); $qf = number_format($q, $q == floor($q) ? 0 : 2); @endphp
                <tr>
                    <td class="amount-cell">{{ enToFa($line['row'] ?? $loop->iteration) }}</td>
                    <td class="text-right val">{{ $line['name'] ?? '-' }}</td>
                    <td colspan="2" class="amount-cell">{{ enToFa($qf) }}</td>
                    <td class="amount-cell">{{ enToFa(number_format($line['unit_price'] ?? 0)) }}</td>
                    <td class="amount-cell">{{ enToFa(number_format($line['gross'] ?? 0)) }}</td>
                    <td class="amount-cell">{{ enToFa(number_format($line['discount'] ?? 0)) }}</td>
                    <td class="amount-cell">{{ enToFa(number_format($line['after_discount'] ?? 0)) }}</td>
                    <td class="amount-cell">{{ enToFa(number_format($line['tax'] ?? 0)) }}</td>
                    <td class="amount-cell font-bold">{{ enToFa(number_format($line['final'] ?? 0)) }}</td>
                    <td class="amount-cell" style="font-size: 6pt;">{{ $line['note'] ?? '-' }}</td>
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
            <td colspan="2" class="text-center font-bold" style="font-size: 9pt;">جمع کل :</td>
            <td colspan="2" class="amount-cell font-bold">{{ enToFa($tqf) }}</td>
            <td class="amount-cell font-bold">-</td>
            <td class="amount-cell font-bold">{{ enToFa(number_format($totals['gross'] ?? 0)) }}</td>
            <td class="amount-cell font-bold">{{ enToFa(number_format($totals['discount'] ?? 0)) }}</td>
            <td class="amount-cell font-bold">{{ enToFa(number_format($totals['after_discount'] ?? 0)) }}</td>
            <td class="amount-cell font-bold">{{ enToFa(number_format($totals['tax'] ?? 0)) }}</td>
            <td class="amount-cell font-bold" style="font-size: 8.5pt;">{{ enToFa(number_format($totals['final'] ?? 0)) }}</td>
            <td class="text-center font-bold">-</td>
        </tr>
