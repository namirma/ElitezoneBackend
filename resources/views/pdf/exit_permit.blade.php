<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>رسید برگشت از فروش - {{ $order->order_number }}</title>
    <style>
        body {
            font-family: 'tahoma', sans-serif;
            direction: rtl;
            padding: 20px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .alert {
            background-color: #dff0d8;
            border: 1px solid #d6e9c6;
            color: #3c763d;
            padding: 10px;
            text-align: center;
            margin-bottom: 15px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .info-table th,
        .info-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: right;
        }

        .info-table th {
            background-color: #f2f2f2;
        }
    </style>
</head>

<body>

    <div class="header">
        <h2>تیتر: "گزارش متمم (نسخه سوپلایر) - سند K"</h2>
        <p>شماره سفارش: {{ $order->order_number }}</p>
        <p>تاریخ: {{ verta()->format('Y/m/d') }}</p>
    </div>

    <div class="alert">
        مبلغ مابه‌التفاوت ناشی از کسری بار به کیف پول شما عودت داده شد.
    </div>

    <h3>اطلاعات باسکول</h3>
    <table class="info-table">
        <tr>
            <th>وزن اولیه (کیلوگرم)</th>
            <td>{{ number_format($order->weight_before_scale) }}</td>
            <th>وزن باسکول (کیلوگرم)</th>
            <td>{{ number_format($order->weight_after_scale) }}</td>
        </tr>
        <tr>
            <th>مقدار کسری</th>
            <td colspan="3" style="color: red; font-weight: bold;">
                {{ number_format($order->weight_before_scale - $order->weight_after_scale) }} کیلوگرم
            </td>
        </tr>
    </table>

    <div style="margin-top: 30px; border: 2px solid #000; padding: 15px; text-align: center;">
        <strong>مبلغ عودت داده شده:</strong><br>
        <span style="font-size: 20px;">{{ number_format(abs($financial_diff ?? 0)) }} ریال</span>
    </div>

</body>

</html>
