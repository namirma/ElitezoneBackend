        {{-- ===== PAYABLE ===== --}}
        <tr class="bg-gray">
            {{-- دقیقاً مطابق اکسل (xl85/xl145/xl152): متنِ مشکیِ بولد روی پس‌زمینه‌ی خاکستری — بدون رنگ --}}
            <td colspan="1" class="text-center font-bold" style="border-left: none; font-size: 9pt; padding: 6px;">{{ $payable_label ?? 'مبلغ قابل پرداخت:' }}</td>
            <td colspan="7" class="text-center font-bold" style="border-right: none; border-left: none; font-size: 8.5pt; white-space: nowrap;">{{ $amount_words ?? 'صفر' }} {{ \App\Support\Money::unitLabel(($money_unit ?? 'rial')) }}</td>
            <td colspan="3" class="text-center font-bold nowrap" style="border-right: none; font-size: 10pt;">{{ enToFa(\App\Support\Money::amountIn($totals['final'] ?? 0, ($money_unit ?? 'rial'))) }}</td>
        </tr>
