<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتور {{ $order->order_number }}</title>
    <style>
        /* لود کردن فونت از مسیر پابلیک برای PDF */
        @font-face {
            font-family: 'IRANYekan';
            src: url('{{ public_path("fonts/Vazir.ttf") }}') format('truetype');
            font-weight: normal;
        }

        @font-face {
            font-family: 'IRANYekan';
            src: url('{{ public_path("fonts/Vazir.ttf") }}') format('truetype'); /* اگر فونت بولد دارید آدرسش را عوض کنید */
            font-weight: bold;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IRANYekan', 'Tahoma', sans-serif; /* استفاده از فونت موجود */
            font-size: 11pt; /* کمی کوچک‌تر برای جا شدن در صفحه */
            line-height: 1.5;
            direction: rtl;
            color: #000;
        }

        .page {
            width: 100%;
            margin: 0 auto;
        }

        /* Header Section */
        .invoice-header {
            width: 100%;
            border: 2px solid #000;
            margin-bottom: 10px;
            display: table; /* برای PDF تیبل بهتر از flex است */
        }

        .header-row { display: table-row; }
        .header-cell {
            display: table-cell;
            padding: 10px;
            vertical-align: middle;
            border-left: 1px solid #000;
        }
        .header-cell:last-child { border-left: none; }

        .header-center h1 {
            font-size: 20pt;
            font-weight: bold;
            text-align: center;
            margin: 0;
        }

        /* Status Box */
        .status-box {
            border: 2px solid #000;
            padding: 10px;
            text-align: center;
            margin-bottom: 10px;
            font-size: 14pt;
            font-weight: bold;
            background-color: #f0f0f0;
        }

        /* Section Headers */
        .section-header {
            background-color: #E9ECEF;
            border: 2px solid #000;
            padding: 8px;
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 5px;
        }

        /* Info Boxes (Buyers/Sellers) */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            margin-bottom: 10px;
        }

        .info-table td {
            border: 1px solid #000;
            padding: 5px;
            font-size: 10pt;
        }

        .info-label {
            font-weight: bold;
            background-color: #f9f9f9;
            width: 15%;
        }

        .info-value { width: 35%; }

        /* Products Table */
        table.products {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            margin: 10px 0;
        }

        table.products th {
            background-color: #f2f2f2;
            border: 1px solid #000;
            padding: 8px 4px;
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
        }

        table.products td {
            border: 1px solid #000;
            padding: 6px 4px;
            text-align: center;
            font-size: 10pt;
        }

        table.products tfoot td {
            font-weight: bold;
            background-color: #f8f9fa;
        }

        /* Total Amount Box */
        .total-box {
            background-color: #E9ECEF;
            border: 2px solid #000;
            padding: 15px;
            margin: 10px 0;
            text-align: center;
        }

        .total-words {
            font-size: 14pt;
            font-weight: bold;
        }

        /* Utilities */
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
    </style>
</head>
<body>
    <div class="page">
        {{-- Header Section --}}
        <div class="invoice-header">
            <div class="header-row">
                <div class="header-cell" style="width: 30%;">
                    <div>شماره سفارش: {{ $order->order_number }}</div>
                    <div>تاریخ صدور: {{ $date_shamsi }}</div>
                    <div>تاریخ اعتبار: {{ $date_shamsi }}</div>
                </div>
                <div class="header-cell header-center" style="width: 40%;">
                    <h1>
                        @if($doc_type === 'initial')
                            پیش فاکتور فروش
                        @elseif($doc_type === 'tax')
                            صورتحساب فروش کالا و خدمات
                        @else
                            فاکتور فروش
                        @endif
                    </h1>
                </div>
                <div class="header-cell" style="width: 30%; text-align: center;">
                    <img src="{{ public_path('images/logo.png') }}" alt="Logo" style="height: 50px;">
                </div>
            </div>
        </div>

        {{-- Status Box --}}
        <div class="status-box">
            وضعیت:
            @if($order->payment_status === 'paid')
                <span style="color: green;">پرداخت شده</span>
            @elseif($order->payment_status === 'pending')
                <span style="color: orange;">در انتظار پرداخت</span>
            @else
                <span style="color: red;">{{ $order->payment_status }}</span>
            @endif
        </div>

        {{-- Buyer Information --}}
        <div class="section-header">مشخصات خریدار</div>
        <table class="info-table">
            <tr>
                <td class="info-label">نام شرکت / موسسه:</td>
                <td class="info-value">{{ $buyer['name'] }}</td>
                <td class="info-label">شناسه ملی:</td>
                <td class="info-value">{{ $buyer['national_id'] }}</td>
            </tr>
            <tr>
                <td class="info-label">شماره اقتصادی:</td>
                <td class="info-value">{{ $buyer['economic_code'] }}</td>
                <td class="info-label">شماره ثبت:</td>
                <td class="info-value">-</td>
            </tr>
            <tr>
                <td class="info-label">استان/شهر:</td>
                <td class="info-value">{{ $buyer['address'] }}</td> <td class="info-label">کدپستی:</td>
                <td class="info-value">{{ $buyer['postal_code'] }}</td>
            </tr>
            <tr>
                <td class="info-label">نشانی:</td>
                <td colspan="3">{{ $buyer['address'] }}</td>
            </tr>
            <tr>
                <td class="info-label">شماره موبایل:</td>
                <td colspan="3">{{ $buyer['phone'] }}</td>
            </tr>
        </table>

        {{-- Seller Information --}}
        <div class="section-header">مشخصات فروشنده</div>
        <table class="info-table">
            <tr>
                <td class="info-label">نام شرکت:</td>
                <td class="info-value">{{ $seller['name'] }}</td>
                <td class="info-label">شناسه ملی:</td>
                <td class="info-value">{{ $seller['national_id'] }}</td>
            </tr>
            <tr>
                <td class="info-label">شماره اقتصادی:</td>
                <td class="info-value">{{ $seller['economic_code'] }}</td>
                <td class="info-label">شماره ثبت:</td>
                <td class="info-value">{{ $seller['reg_id'] }}</td>
            </tr>
            <tr>
                <td class="info-label">استان/شهر:</td>
                <td class="info-value">اصفهان / اصفهان</td>
                <td class="info-label">کدپستی:</td>
                <td class="info-value">{{ $seller['postal_code'] }}</td>
            </tr>
            <tr>
                <td class="info-label">نشانی:</td>
                <td colspan="3">{{ $seller['address'] }}</td>
            </tr>
            <tr>
                <td class="info-label">تلفن:</td>
                <td class="info-value">{{ $seller['phone'] }}</td>
                <td class="info-label"></td>
                <td class="info-value"></td>
            </tr>
        </table>

        {{-- Products Table --}}
        <div class="section-header">مشخصات کالا یا خدمات مورد معامله</div>
        <table class="products">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 5%;">ردیف</th>
                    <th rowspan="2" style="width: 25%;">شرح کالا</th>
                    <th colspan="2" style="width: 15%;">واحد (کیلوگرم)</th>
                    <th colspan="5">مبالغ (ریال)</th>
                </tr>
                <tr>
                    <th>مقدار</th>
                    <th>واحد</th>
                    <th>مبلغ واحد</th>
                    <th>مبلغ کل</th>
                    <th style="color: #dc3545;">تخفیف</th>
                    <th>مالیات</th>
                    <th>مبلغ نهایی</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalQty = 0;
                    $sumTax = 0;
                    $sumDiscount = 0;
                    $sumTotal = 0;
                @endphp
                @foreach($order->items as $index => $item)
                    @php
                        // محاسبات ساده داخل ویو برای جلوگیری از ارور
                        $qty = $item->quantity;
                        $price = $item->price;
                        $subtotal = $qty * $price;
                        $discount = $item->discount_amount ?? 0;
                        $afterDisc = $subtotal - $discount;
                        $tax = $item->tax_amount ?? 0; // اگر در دیتابیس ندارید 0 در نظر گرفته می‌شود
                        $final = $afterDisc + $tax;

                        // جمع کل
                        $totalQty += $qty;
                        $sumTax += $tax;
                        $sumDiscount += $discount;
                        $sumTotal += $final;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="text-right">{{ $item->productVariant->name ?? $item->product->name ?? 'محصول' }}</td>
                        <td>{{ number_format($qty) }}</td>
                        <td>کیلوگرم</td>
                        <td>{{ number_format($price) }}</td>
                        <td>{{ number_format($subtotal) }}</td>
                        <td>{{ number_format($discount) }}</td>
                        <td>{{ number_format($tax) }}</td>
                        <td>{{ number_format($final) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">جمع کل:</td>
                    <td>{{ number_format($totalQty) }}</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>{{ number_format($sumDiscount) }}</td>
                    <td>{{ number_format($sumTax) }}</td>
                    <td>{{ number_format($sumTotal) }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- Total Amount in Words --}}
        <div class="total-box">
            <div class="total-words">مبلغ قابل پرداخت: {{ $amount_words }} ریال</div>
            <div style="margin-top: 5px; font-size: 16pt;">{{ number_format($sumTotal) }}</div>
        </div>

        {{-- Signatures --}}
        <table style="width: 100%; border: none; margin-top: 30px;">
            <tr>
                <td style="border: none; text-align: center; width: 50%;">
                    <strong>مهر و امضای فروشنده</strong>
                </td>
                <td style="border: none; text-align: center; width: 50%;">
                    <strong>مهر و امضای خریدار</strong>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
