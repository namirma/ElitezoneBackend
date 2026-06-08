        {{-- ===== SELLER ===== --}}
        <tr><td colspan="11" class="section-title">مشخصات فروشنده</td></tr>
        <tr>
            <td class="lbl text-right">نام شخص حقیقی/حقوقی:</td>
            <td colspan="4" class="val text-right">{{ $seller['name'] ?? '-' }}</td>
            <td class="lbl text-right">شماره اقتصادی:</td>
            <td colspan="3" class="val text-right">{{ enToFa($seller['economic_code'] ?? '-') }}</td>
            <td class="lbl text-right">شماره ثبت:</td>
            <td class="val text-right">{{ enToFa($seller['reg_id'] ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">شهر/استان:</td>
            <td colspan="4" class="val text-right">{{ $seller['city'] ?? 'اصفهان/اصفهان' }}</td>
            <td class="lbl text-right">کد پستی:</td>
            <td colspan="3" class="val text-right">{{ enToFa($seller['postal_code'] ?? '-') }}</td>
            <td class="lbl text-right">شناسه ملی:</td>
            <td class="val text-right">{{ enToFa($seller['national_id'] ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">نشانی:</td>
            <td colspan="4" class="val text-right">{{ enToFa($seller['address'] ?? '-') }}</td>
            <td class="lbl text-right">شماره تلفن/نمابر:</td>
            <td colspan="5" class="val text-right">{{ enToFa($seller['phone'] ?? '-') }}</td>
        </tr>
