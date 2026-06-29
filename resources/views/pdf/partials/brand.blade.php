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
