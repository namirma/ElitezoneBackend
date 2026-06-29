        {{-- ===== PAYABLE ===== --}}
        <tr class="bg-gray">
            {{-- دقیقاً مطابق اکسل (xl85/xl145/xl152): متنِ مشکیِ بولد روی پس‌زمینهٔ خاکستری — بدون رنگ --}}
            <td colspan="1" class="text-center font-bold" style="border-left: none; font-size: 9pt; padding: 6px;">مبلغ قابل پرداخت:</td>
            <td colspan="7" class="text-center font-bold" style="border-right: none; border-left: none; font-size: 8.5pt; white-space: nowrap;">{{ $amount_words ?? 'صفر' }} ریال</td>
            <td colspan="3" class="text-center font-bold nowrap" style="border-right: none; font-size: 10pt;">{{ enToFa(number_format($totals['final'] ?? 0)) }}</td>
        </tr>
