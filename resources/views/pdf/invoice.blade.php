
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @font-face {
            font-family: 'yekan';
            src: url('{{ storage_path("fonts/YekanBakh-Regular.ttf") }}') format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        @font-face {
            font-family: 'yekan';
            src: url('{{ storage_path("fonts/YekanBakh-Bold.ttf") }}') format('truetype');
            font-weight: bold;
            font-style: normal;
        }

        body {
            font-family: 'yekan', sans-serif;
            font-size: 9pt;
            direction: rtl;
            text-align: right;
            margin: 0;
            padding: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            table-layout: fixed;
        }
        td, th {
            border: 1px solid #000;
            padding: 3px;
            text-align: center;
            vertical-align: middle;
            line-height: 1.4;
        }
        .no-border, .no-border td {
            border: none !important;
        }
        .text-right { text-align: right !important; padding-right: 5px; }
        .text-center { text-align: center !important; }
        .bold { font-weight: bold; }
        .bg-gray { background-color: #d9d9d9; }

        /* استایل‌های اختصاصی برای شباهت به PDF */
        .header-title {
            font-size: 14pt;
            font-weight: bold;
            border: 1px solid #000;
            padding: 8px 30px;
            border-radius: 5px;
            display: inline-block;
        }

        /* تنظیم عرض ستون‌های جداول مشخصات */
        .label-col { width: 12%; background-color: #d9d9d9; font-weight: bold; font-size: 8pt; }
        .val-col { width: 21%; }

        /* تنظیم عرض ستون‌های جدول کالا */
        .col-row { width: 5%; }
        .col-desc { width: 30%; }
        .col-qty { width: 7%; }
        .col-unit { width: 7%; }
        .col-price { width: 10%; }

        .num { font-feature-settings: "ss01"; }

        .checkbox {
            display: inline-block;
            width: 10px;
            height: 10px;
            border: 1px solid #000;
            margin-left: 3px;
            vertical-align: middle;
        }
        .checked { background-color: #000; }
    </style>
</head>
<body>

    <table class="no-border">
        <tr>
            <td style="width: 25%; padding: 0;">
                <table style="margin: 0;">
                    <tr>
                        <td class="bg-gray bold text-right" style="width: 40%;">شماره:</td>
                        <td class="num">{{ $order->order_number }}</td>
                    </tr>
                    <tr>
                        <td class="bg-gray bold text-right">تاریخ:</td>
                        <td class="num">{{ $date }}</td>
                    </tr>
                    <tr>
                        <td class="bg-gray bold text-right">اعتبار:</td>
                        <td class="num">{{ $validity_date ?? '-' }}</td>
                    </tr>
                </table>
            </td>

            <td style="width: 50%; text-align: center;">
                <div class="header-title">فاکتور فروش کالا و خدمات</div>
            </td>

            <td style="width: 25%; text-align: left; vertical-align: middle;">
                <h2 style="margin: 0; color: #333; font-family: sans-serif;">ELITEZONE</h2>
                <small style="font-family: sans-serif;">www.elitezone.me</small>
            </td>
        </tr>
    </table>

    <table style="margin-top: 5px;">
        <tr>
            <td colspan="6" class="bg-gray bold text-center" style="padding: 2px;">مشخصات فروشنده</td>
        </tr>
        <tr>
            <td class="label-col text-right">نام شخص حقیقی/حقوقی:</td>
            <td class="val-col text-right">شرکت نرم افزاری سازه اسپادانا</td>
            <td class="label-col text-right">شماره اقتصادی:</td>
            <td class="val-col num">411347688653</td>
            <td class="label-col text-right">شماره ثبت:</td>
            <td class="val-col num">31106</td>
        </tr>
        <tr>
            <td class="label-col text-right">شهر / استان:</td>
            <td class="val-col text-right">اصفهان / اصفهان</td>
            <td class="label-col text-right">کد پستی:</td>
            <td class="val-col num">8158184500</td>
            <td class="label-col text-right">شناسه ملی:</td>
            <td class="val-col num">10260516722</td>
        </tr>
        <tr>
            <td class="label-col text-right">نشانی:</td>
            <td colspan="3" class="text-right" style="font-size: 8pt;">
                پارک علم و فناوری / خیابان سلمان فارسی (مشتاق سوم) / بعد از پل شهرستان / ساختمان شهرک علمی تحقیقاتی / طبقه اول / واحد 114
            </td>
            <td class="label-col text-right">تلفن/نمابر:</td>
            <td class="val-col num">03134005000</td>
        </tr>
    </table>

    <table style="margin-top: 5px;">
        <tr>
            <td colspan="6" class="bg-gray bold text-center" style="padding: 2px;">مشخصات خریدار</td>
        </tr>
        <tr>
            <td class="label-col text-right">نام شخص حقیقی/حقوقی:</td>
            <td class="val-col text-right">{{ $buyer['name'] }}</td>
            <td class="label-col text-right">شماره اقتصادی:</td>
            <td class="val-col num">{{ $buyer['economic_code'] ?? '-' }}</td>
            <td class="label-col text-right">شماره ثبت:</td>
            <td class="val-col num">{{ $buyer['reg_id'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label-col text-right">شهر / استان:</td>
            <td class="val-col text-right">{{ $order->address->province->name ?? '-' }} / {{ $order->address->city->name ?? '-' }}</td>
            <td class="label-col text-right">کد پستی:</td>
            <td class="val-col num">{{ $buyer['postal_code'] }}</td>
            <td class="label-col text-right">شناسه ملی:</td>
            <td class="val-col num">{{ $buyer['national_id'] }}</td>
        </tr>
        <tr>
            <td class="label-col text-right">نشانی:</td>
            <td colspan="3" class="text-right" style="font-size: 8pt;">{{ $buyer['address'] }}</td>
            <td class="label-col text-right">تلفن/نمابر:</td>
            <td class="val-col num">{{ $buyer['phone'] }}</td>
        </tr>
    </table>

    <table style="margin-top: 10px;">
        <thead>
            <tr class="bg-gray">
                <td colspan="10" class="bold text-center" style="padding: 2px;">مشخصات کالا یا خدمات مورد معامله</td>
            </tr>
            <tr class="bg-gray text-center" style="font-size: 8pt;">
                <th class="col-row">ردیف</th>
                <th class="col-desc">شرح کالا یا خدمات</th>
                <th class="col-qty">مقدار</th>
                <th class="col-unit">واحد</th>
                <th class="col-price">مبلغ واحد <br><span style="font-size:7pt">(ریال)</span></th>
                <th class="col-price">مبلغ کل <br><span style="font-size:7pt">(ریال)</span></th>
                <th style="width: 8%;">تخفیف <br><span style="font-size:7pt">(ریال)</span></th>
                <th class="col-price">مبلغ پس از تخفیف <br><span style="font-size:7pt">(ریال)</span></th>
                <th style="width: 8%;">جمع مالیات <br><span style="font-size:7pt">(ریال)</span></th>
                <th class="col-price">مبلغ قابل پرداخت <br><span style="font-size:7pt">(ریال)</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $index => $item)
            @php
                $qty = $item->quantity;
                $unitPrice = $item->unit_price;
                $total = $qty * $unitPrice;
                $discount = $item->discount_amount ?? 0;
                $afterDiscount = $total - $discount;
                $tax = $item->tax_amount ?? ($afterDiscount * 0.10);
                $final = $afterDiscount + $tax;
            @endphp
            <tr>
                <td class="num">{{ $index + 1 }}</td>
                <td class="text-right">{{ $item->productVariant->name ?? 'محصول' }}</td>
                <td class="num">{{ number_format($qty) }}</td>
                <td>{{ $item->unit ?? 'کیلوگرم' }}</td>
                <td class="num">{{ number_format($unitPrice) }}</td>
                <td class="num">{{ number_format($total) }}</td>
                <td class="num">{{ number_format($discount) }}</td>
                <td class="num">{{ number_format($afterDiscount) }}</td>
                <td class="num">{{ number_format($tax) }}</td>
                <td class="num bold">{{ number_format($final) }}</td>
            </tr>
            @endforeach

            <tr class="bg-gray bold">
                <td colspan="2" class="text-right">جمع کل:</td>
                <td class="num">{{ number_format($order->items->sum('quantity')) }}</td>
                <td>{{ $order->items->first()->unit ?? '-' }}</td>
                <td>-</td>
                <td class="num">{{ number_format($order->subtotal) }}</td>
                <td class="num">{{ number_format($order->discount_amount) }}</td>
                <td class="num">{{ number_format($order->subtotal - $order->discount_amount) }}</td>
                <td class="num">{{ number_format($order->vat_amount) }}</td>
                <td class="num">{{ number_format($order->final_amount) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="margin-top: 5px;">
        <tr>
            <td class="bg-gray bold text-right" style="width: 15%;">مبلغ قابل پرداخت:</td>
            <td class="text-right bold num" style="width: 85%; font-size: 10pt;">
                {{ $amount_words }} ریال
            </td>
        </tr>
    </table>

    <table style="margin-top: 5px;">
        <tr>
            <td class="bg-gray bold text-right" style="width: 15%;">شرایط و نحوه فروش:</td>
            <td class="text-right" style="width: 85%;">
                <span style="margin-left: 20px;">
                    <span class="checkbox checked"></span> نقدی
                </span>
                <span>
                    <span class="checkbox"></span> غیر نقدی
                </span>
            </td>
        </tr>
        <tr>
            <td class="bg-gray bold text-right">روش پرداخت:</td>
            <td class="text-right" style="font-size: 8pt;">
                به صورت واریز به شماره حساب .......................... شماره شبا ................................................. بانک ....................... به نام ............................................ می‌باشد.
            </td>
        </tr>
    </table>

    <table class="no-border" style="margin-top: 10px;">
        <tr class="no-border">
            <td style="width: 55%; border: 1px solid #000; height: 100px; vertical-align: top; text-align: right; padding: 5px;">
                <span class="bold bg-gray" style="padding: 2px 5px;">توضیحات مهم:</span>
                <p style="font-size: 8pt; margin-top: 5px; line-height: 1.5;">
                    مهلت واریز مبلغ پیش فاکتور تا ساعت 10:00 صبح روز جاری می‌باشد. در صورت عدم واریز وجه، سفارش لغو خواهد شد.
                </p>
                <div class="text-center bold" style="margin-top: 30px;">مهر و امضای فروشنده</div>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 43%; border: 1px solid #000; height: 100px; vertical-align: top;">
                <br><br><br><br>
                <div class="text-center bold">مهر و امضای خریدار</div>
            </td>
        </tr>
    </table>

</body>
</html>

