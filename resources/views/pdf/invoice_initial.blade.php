<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>پیش فاکتور فروش {{ $invoice_number ?? ($order->order_number ?? '') }}</title>
    @include('pdf.partials.styles')
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
            <td colspan="9" rowspan="3" class="title-main text-center bg-white">پیش فاکتور فروش</td>
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
                {{ $payment_label ?? 'پرداخت نشده' }}
            </td>
        </tr>

        @include('pdf.partials.buyer_section')
        @include('pdf.partials.seller_section')
        @include('pdf.partials.items_section')
        @include('pdf.partials.payable_row')

        {{-- ===== NOTES ===== --}}
        <tr>
            {{-- خط عمودی مشکی بین لیبل و متن حذف شد --}}
            <td colspan="1" class="bg-gray font-bold text-center" valign="top" style="border-left: none; padding-top: 10px;">توضیحات مهم</td>
            <td colspan="10" class="notes-text text-right bg-white" valign="top" style="border-right: none;">
                @if(!empty($order->notes))
                    {{ $order->notes }}
                @else
                    مهلت واریز مبلغ پیش فاکتور تا ساعت ۱۰:۰۰ صبح روز چهارشنبه می‌باشد. در صورتی که وجه پیش فاکتور قبل از این مهلت پرداخت نگردد پیش فاکتور صادره منقضی شده و از درجه اعتبار ساقط می‌گردد و خریدار حق هیچگونه مطالبه ای ندارد.<br>
                    زمان تحویل محصولات فوق طبق برنامه تولید مجتمع فولاد می‌باشد.<br>
                    محصولات فوق با نشان کارخانه عرضه می گردد.<br>
                    <span class="text-red font-bold">بعد از صدور فاکتور چنانچه وزن ارسالی با وزن پیش فاکتور متفاوت بود، مابه‌التفاوت حساب از طریق کیف پول تسویه می‌شود.</span>
                @endif
            </td>
        </tr>
    </table>

</body>
</html>
