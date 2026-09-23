{{--
    سند T — نظرسنجی از الیت یار.
    داده از order_surveys با type = elite_yar خوانده می‌شود (OrderSurvey::TYPE_ELITE_YAR).
--}}
@php
    $survey = $order->surveys->firstWhere(\App\Models\OrderSurvey::TYPE, \App\Models\OrderSurvey::TYPE_ELITE_YAR);
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>نظرسنجی الیت یار {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
</head>
<body>

    @include('pdf.partials.brand')

    <table class="master-grid">
        @include('pdf.partials.colgroup')

        {{-- ===== HEADER ===== --}}
        <tr>
            <td class="lbl text-right">شماره سند :</td>
            <td class="val text-right font-bold">{{ enToFa($invoice_number ?? '-') }}</td>
            <td colspan="9" rowspan="3" class="title-main text-center bg-white">فرم نظرسنجی الیت یار</td>
        </tr>
        <tr>
            <td class="lbl text-right">شماره سفارش :</td>
            <td class="val text-right">{{ enToFa($order->order_number ?? '-') }}</td>
        </tr>
        <tr>
            <td class="lbl text-right">تاریخ صدور :</td>
            <td class="val text-right">{{ enToFa($date_shamsi ?? '-') }}</td>
        </tr>

        @include('pdf.partials.buyer_section')

        {{-- ===== SURVEY ===== --}}
        <tr><td colspan="11" class="section-title">نتیجه‌ی نظرسنجی</td></tr>

        @if ($survey)
            <tr>
                <td colspan="3" class="lbl text-right font-bold">الیت یار</td>
                <td colspan="8" class="val text-right">{{ $survey->user?->profile?->name ?? $survey->user?->mobile ?? '-' }}</td>
            </tr>
            <tr>
                <td colspan="3" class="lbl text-right font-bold">امتیاز رضایت (از ۵)</td>
                <td colspan="8" class="val text-right">{{ enToFa($survey->rating ?? '-') }}</td>
            </tr>
            <tr>
                <td colspan="3" class="lbl text-right font-bold">تاریخ ثبت</td>
                <td colspan="8" class="val text-right">{{ enToFa(\App\Support\JalaliDate::format($survey->created_at, 'Y/m/d')) }}</td>
            </tr>
            <tr>
                <td colspan="3" class="lbl text-right font-bold" valign="top">توضیحات الیت یار</td>
                <td colspan="8" class="notes-text text-right bg-white" valign="top">{{ $survey->comment ?: '—' }}</td>
            </tr>
        @else
            <tr>
                <td colspan="11" class="notes-text text-center bg-white">نظرسنجی الیت یار برای این سفارش هنوز ثبت نشده است.</td>
            </tr>
        @endif

        {{-- ===== NOTES ===== --}}
        <tr>
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                این سند صرفا بازتاب نظر ثبت‌شده‌ی الیت یار است و هیچ اثر مالی بر سفارش ندارد.
            </td>
        </tr>
    </table>

</body>
</html>
