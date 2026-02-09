<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>رسید نهایی - {{ $order->order_number }}</title>
    <style>
        body { font-family: 'tahoma', sans-serif; direction: rtl; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table th, .info-table td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        .info-table th { background-color: #f2f2f2; }
        .total-box { border: 2px solid #000; padding: 10px; text-align: center; font-weight: bold; font-size: 18px; }
    </style>
</head>
<body>

    <div class="header">
        <h2> گزارش نهایی سوپلایر (سند H)</h2>
        <p>تاریخ: {{ $date_shamsi }}</p>
        <p>شماره سفارش: {{ $order->order_number }}</p>
    </div>

    <h3>مشخصات خریدار</h3>
    <table class="info-table">
        <tr>
            <th>نام خریدار</th>
            <td>{{ $buyer['name'] }}</td>
            <th>شماره ملی/اقتصادی</th>
            <td>{{ $buyer['national_id'] }}</td>
        </tr>
        <tr>
            <th>تلفن</th>
            <td>{{ $buyer['phone'] }}</td>
            <th>آدرس</th>
            <td>{{ $buyer['address'] }}</td>
        </tr>
    </table>

    <h3>جزئیات سفارش</h3>
    <table class="info-table">
        <thead>
            <tr>
                <th>شرح کالا</th>
                <th>مقدار (وزن)</th>
                <th>فی (ریال)</th>
                <th>مبلغ کل (ریال)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            <tr>
                <td>{{ $item->productVariant->name ?? 'محصول' }}</td>
                <td>{{ number_format($item->quantity ?? 0) }}</td>
                <td>{{ number_format($item->price ?? 0) }}</td>
                <td>{{ number_format($item->final_price ?? 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-box">
        مبلغ نهایی پرداخت شده: {{ number_format($order->payment_of_owner) }} ریال
        <br>
        <small>({{ $amount_words }} ریال)</small>
    </div>

    <div style="margin-top: 50px;">
        <p>مهر و امضای فروشنده: <strong>{{ $seller['name'] }}</strong></p>
    </div>

</body>
</html>
