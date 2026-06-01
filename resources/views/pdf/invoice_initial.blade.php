<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>پیش فاکتور فروش {{ $invoice_number ?? $order->order_number }}</title>
    <style>
        /* ============================================================
           طراحی دقیقاً مطابق فایل اکسل تیم UI/UX (sheet001 / stylesheet.css):
           - عرض ستون‌ها B..L: 10.01 / 22.39 / 4.84 / 6.15 / 5.32 / 8.07×5 / 10.94
           - فونت Yekan Bakh FaNum، ارقام فارسی
           - رنگ‌ها: خاکستری بخش‌ها #E9ECEF ، نوار وضعیت #D60A69 ،
             عنوان واحدها خاکستری #A6A6A6 ، «تخفیف» قرمز
           ============================================================ */
        * { box-sizing: border-box; }

        body {
            font-family: yekanbakh, "Yekan Bakh FaNum", sans-serif;
            direction: rtl;
            color: #000;
            margin: 0;
            padding: 0;
            font-size: 7pt;
        }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { vertical-align: middle; }

        /* ---------- 1) BRAND BAR (لوگو چپ، آدرس سایت راست) ---------- */
        table.brand { margin-bottom: 4px; }
        table.brand td { border: none; padding: 0 2px 3px; vertical-align: bottom; }
        td.brand-web  { text-align: right; }
        td.brand-logo { text-align: left; }
        td.brand-web  img { width: 235px; height: 20px; }
        td.brand-logo img { width: 188px; height: 34px; }

        /* ---------- borders ---------- */
        .grid td { border: 0.75pt solid #000; }

        /* ---------- 2) HEADER ---------- */
        .lbl   { font-weight: bold; text-align: right; padding: 4pt 6pt; font-size: 7pt; white-space: nowrap; }
        .val   { font-weight: bold; text-align: right; padding: 4pt 6pt; font-size: 7.5pt; }
        .title { font-weight: bold; text-align: center; font-size: 13pt; }

        /* ---------- 3) STATUS ---------- */
        table.status td { border: 1pt solid #000; }
        .st-lbl, .st-val {
            background: #D60A69; color: #fff; font-weight: bold;
            text-align: center; font-size: 11pt; padding: 7pt 4pt;
        }
        .st-lbl { border-right: 1pt solid #000; }

        /* ---------- 4) SECTION TITLE ---------- */
        .section {
            background: #E9ECEF; font-weight: bold; text-align: center;
            font-size: 10pt; padding: 4pt; border: 1pt solid #000;
        }

        /* ---------- 5) PARTY (buyer / seller) ---------- */
        .p-lbl { font-weight: bold; text-align: right; font-size: 6.5pt; padding: 3.5pt 5pt; white-space: nowrap; }
        .p-val { text-align: right; font-size: 7pt; padding: 3.5pt 6pt; }

        /* ---------- 6) ITEMS ---------- */
        table.items td { border: 0.75pt solid #000; }
        /* کادر بیرونی ضخیم */
        table.items { border: 1.5pt solid #000; }

        .ih       { font-weight: normal; text-align: center; font-size: 7.5pt; padding: 3pt 2pt; background: #fff; }
        .ih-unit  { color: #A6A6A6; text-align: center; font-size: 7.5pt; padding: 3pt 2pt; background: #fff; border-bottom: none; }
        .ih-sub   { text-align: center; font-size: 7pt; padding: 3pt 2pt; background: #fff; border-top: none; border-bottom: 1.5pt solid #000; }
        .ih-sub.red { color: #FF0000; font-weight: bold; }
        .ih-bottom { border-bottom: 1.5pt solid #000; }

        .it-num  { text-align: center; font-size: 7.5pt; padding: 4pt 2pt; }
        .it-desc { text-align: right;  font-size: 7pt;   padding: 4pt 6pt; }
        .it-qty  { text-align: center; font-size: 7.5pt; padding: 4pt 2pt; }
        .it-amt  { text-align: left;   font-size: 7.5pt; padding: 4pt 6pt; }
        .it-note { text-align: center; font-size: 7pt; }

        /* جمع کل */
        .tot     { font-weight: bold; font-size: 8pt; padding: 4pt 6pt; }
        .tot-c   { text-align: center; }
        .tot-l   { text-align: left; }
        .tot-r   { text-align: right; }

        /* ---------- 7) PAYABLE ---------- */
        table.payable td { border: 1pt solid #000; background: #E9ECEF; }
        .pay-lbl   { font-weight: bold; text-align: right; font-size: 8pt; padding: 5pt 7pt; }
        .pay-words { font-weight: bold; text-align: right; font-size: 8pt; padding: 5pt 7pt; }
        .pay-amt   { font-weight: bold; text-align: left;  font-size: 9pt; padding: 5pt 7pt; }

        /* ---------- 8) NOTES ---------- */
        table.notes td { border: 1pt solid #000; }
        .notes-lbl  { background: #E9ECEF; font-weight: bold; text-align: center; font-size: 8pt; padding: 5pt; }
        .notes-body { text-align: right; font-size: 7pt; line-height: 1.55; padding: 4pt 8pt; }
        .notes-body .red { color: #FF0000; }
    </style>
</head>
<body>

    {{-- ================= BRAND ================= --}}
    <table class="brand">
        <tr>
            <td class="brand-web" style="width:50%;">
                @if(!empty($brand_web))<img src="{{ $brand_web }}" alt="www.elitezone.me">@endif
            </td>
            <td class="brand-logo" style="width:50%;">
                @if(!empty($brand_logo))<img src="{{ $brand_logo }}" alt="ELITEZONE">@endif
            </td>
        </tr>
    </table>

    {{-- ================= HEADER ================= --}}
    <table class="grid">
        <colgroup>
            <col style="width:10.01%;"><col style="width:22.39%;"><col style="width:67.60%;">
        </colgroup>
        <tr>
            <td class="lbl">شماره سفارش :</td>
            <td class="val">{{ enToFa($invoice_number ?? $order->order_number) }}</td>
            <td rowspan="3" class="title">پیش فاکتور فروش</td>
        </tr>
        <tr>
            <td class="lbl">تاریخ صدور :</td>
            <td class="val">{{ enToFa($date_shamsi) }}</td>
        </tr>
        <tr>
            <td class="lbl">تاریخ اعتبار :</td>
            <td class="val">{{ enToFa($validity_shamsi ?? $date_shamsi) }}</td>
        </tr>
    </table>

    {{-- ================= STATUS ================= --}}
    <table class="status">
        <colgroup><col style="width:32.40%;"><col style="width:67.60%;"></colgroup>
        <tr>
            <td class="st-lbl">وضعیت</td>
            <td class="st-val">{{ $payment_label ?? 'پرداخت نشده' }}</td>
        </tr>
    </table>

    {{-- ================= BUYER ================= --}}
    <table class="grid">
        <colgroup>
            <col style="width:10.01%;"><col style="width:22.39%;"><col style="width:4.84%;">
            <col style="width:6.15%;"><col style="width:5.32%;"><col style="width:8.07%;">
            <col style="width:8.07%;"><col style="width:8.07%;"><col style="width:8.07%;">
            <col style="width:8.07%;"><col style="width:10.94%;">
        </colgroup>
        <tr><td colspan="11" class="section">مشخصات خریدار</td></tr>
        <tr>
            <td class="p-lbl">نام و نام خانوادگی :</td>
            <td colspan="4" class="p-val">{{ $buyer['name'] ?? '' }}</td>
            <td class="p-lbl">کد ملی :</td>
            <td colspan="3" class="p-val">{{ enToFa($buyer['national_id'] ?? '') }}</td>
            <td class="p-lbl">شماره اقتصادی :</td>
            <td class="p-val">{{ enToFa($buyer['economic_code'] ?? '') }}</td>
        </tr>
        <tr>
            <td class="p-lbl">استان :</td>
            <td colspan="4" class="p-val">{{ $buyer['province'] ?? '' }}</td>
            <td class="p-lbl">شهر :</td>
            <td colspan="3" class="p-val">{{ $buyer['city'] ?? '' }}</td>
            <td class="p-lbl">کد پستی :</td>
            <td class="p-val">{{ enToFa($buyer['postal_code'] ?? '') }}</td>
        </tr>
        <tr>
            <td class="p-lbl">نشانی :</td>
            <td colspan="4" class="p-val">{{ $buyer['address'] ?? '' }}</td>
            <td class="p-lbl">شماره موبایل :</td>
            <td colspan="5" class="p-val">{{ enToFa($buyer['phone'] ?? '') }}</td>
        </tr>
    </table>

    {{-- ================= SELLER ================= --}}
    <table class="grid">
        <colgroup>
            <col style="width:10.01%;"><col style="width:22.39%;"><col style="width:4.84%;">
            <col style="width:6.15%;"><col style="width:5.32%;"><col style="width:8.07%;">
            <col style="width:8.07%;"><col style="width:8.07%;"><col style="width:8.07%;">
            <col style="width:8.07%;"><col style="width:10.94%;">
        </colgroup>
        <tr><td colspan="11" class="section">مشخصات فروشنده</td></tr>
        <tr>
            <td class="p-lbl">نام شخص حقیقی/حقوقی :</td>
            <td colspan="4" class="p-val">{{ $seller['name'] }}</td>
            <td class="p-lbl">شماره اقتصادی :</td>
            <td colspan="3" class="p-val">{{ enToFa($seller['economic_code']) }}</td>
            <td class="p-lbl">شماره ثبت :</td>
            <td class="p-val">{{ enToFa($seller['reg_id']) }}</td>
        </tr>
        <tr>
            <td class="p-lbl">شهر/استان :</td>
            <td colspan="4" class="p-val">{{ $seller['city'] ?? 'اصفهان/اصفهان' }}</td>
            <td class="p-lbl">کد پستی :</td>
            <td colspan="3" class="p-val">{{ enToFa($seller['postal_code']) }}</td>
            <td class="p-lbl">شناسه ملی :</td>
            <td class="p-val">{{ enToFa($seller['national_id']) }}</td>
        </tr>
        <tr>
            <td class="p-lbl">نشانی :</td>
            <td colspan="4" class="p-val">{{ enToFa($seller['address']) }}</td>
            <td class="p-lbl">شماره تلفن :</td>
            <td colspan="3" class="p-val">{{ enToFa($seller['phone']) }}</td>
            <td class="p-lbl">شماره نمابر :</td>
            <td class="p-val">{{ enToFa($seller['fax'] ?? $seller['phone']) }}</td>
        </tr>
    </table>

    {{-- ================= ITEMS ================= --}}
    <table class="grid"><colgroup><col style="width:100%;"></colgroup>
        <tr><td class="section">مشخصات کالا یا خدمات مورد معامله</td></tr>
    </table>
    <table class="items">
        <colgroup>
            <col style="width:10.01%;"><col style="width:22.39%;"><col style="width:4.84%;">
            <col style="width:6.15%;"><col style="width:5.32%;"><col style="width:8.07%;">
            <col style="width:8.07%;"><col style="width:8.07%;"><col style="width:8.07%;">
            <col style="width:8.07%;"><col style="width:10.94%;">
        </colgroup>
        {{-- header --}}
        <tr>
            <td rowspan="2" class="ih ih-bottom">ردیف</td>
            <td rowspan="2" class="ih ih-bottom">شرح کالا یا خدمات</td>
            <td colspan="2" class="ih-unit">واحد(کیلوگرم)</td>
            <td colspan="6" class="ih-unit">واحد مبالغ (ریال)</td>
            <td rowspan="2" class="ih ih-bottom">توضیحات کالا</td>
        </tr>
        <tr>
            <td colspan="2" class="ih-sub">مقدار</td>
            <td class="ih-sub">مبلغ واحد</td>
            <td class="ih-sub">مبلغ</td>
            <td class="ih-sub red">تخفیف</td>
            <td class="ih-sub">مبلغ پس از تخفیف</td>
            <td class="ih-sub">جمع مالیات</td>
            <td class="ih-sub">مبلغ قابل پرداخت</td>
        </tr>
        {{-- data --}}
        @foreach($line_items as $line)
            @php $q = (float) $line['quantity']; $qf = number_format($q, $q == floor($q) ? 0 : 2); @endphp
            <tr>
                <td class="it-num">{{ enToFa($line['row']) }}</td>
                <td class="it-desc">{{ $line['name'] }}</td>
                <td colspan="2" class="it-qty">{{ enToFa($qf) }}</td>
                <td class="it-amt">{{ enToFa(number_format($line['unit_price'])) }}</td>
                <td class="it-amt">{{ enToFa(number_format($line['gross'])) }}</td>
                <td class="it-amt">{{ enToFa(number_format($line['discount'])) }}</td>
                <td class="it-amt">{{ enToFa(number_format($line['after_discount'])) }}</td>
                <td class="it-amt">{{ enToFa(number_format($line['tax'])) }}</td>
                <td class="it-amt">{{ enToFa(number_format($line['final'])) }}</td>
                <td class="it-note">{{ $line['note'] ?? '' }}</td>
            </tr>
        @endforeach
        {{-- totals --}}
        @php $tq = (float) $totals['quantity']; $tqf = number_format($tq, $tq == floor($tq) ? 0 : 2); @endphp
        <tr>
            <td colspan="2" class="tot tot-r">جمع کل :</td>
            <td colspan="2" class="tot tot-c">{{ enToFa($tqf) }}</td>
            <td class="tot tot-c">-</td>
            <td class="tot tot-l">{{ enToFa(number_format($totals['gross'])) }}</td>
            <td class="tot tot-l">{{ enToFa(number_format($totals['discount'])) }}</td>
            <td class="tot tot-l">{{ enToFa(number_format($totals['after_discount'])) }}</td>
            <td class="tot tot-l">{{ enToFa(number_format($totals['tax'])) }}</td>
            <td class="tot tot-l">{{ enToFa(number_format($totals['final'])) }}</td>
            <td class="tot tot-c">-</td>
        </tr>
    </table>

    {{-- ================= PAYABLE ================= --}}
    <table class="payable">
        <colgroup><col style="width:10.01%;"><col style="width:46.77%;"><col style="width:43.22%;"></colgroup>
        <tr>
            <td class="pay-lbl">مبلغ قابل پرداخت :</td>
            <td class="pay-words">{{ $amount_words }} ریال</td>
            <td class="pay-amt">{{ enToFa(number_format($totals['final'])) }}</td>
        </tr>
    </table>

    {{-- ================= NOTES ================= --}}
    <table class="notes">
        <colgroup><col style="width:10.01%;"><col style="width:89.99%;"></colgroup>
        <tr>
            <td class="notes-lbl">توضیحات مهم</td>
            <td class="notes-body">
                @if(!empty($order->notes))
                    {{ $order->notes }}
                @else
                    مهلت واریز مبلغ پیش فاکتور تا ساعت ۱۰:۰۰ صبح روز چهارشنبه می‌باشد. در صورتی که وجه پیش فاکتور قبل از این مهلت پرداخت نگردد پیش فاکتور صادره منقضی شده و از درجه اعتبار ساقط می‌گردد و خریدار حق هیچگونه مطالبه ای ندارد.<br>
                    زمان تحویل محصولات فوق طبق برنامه تولید مجتمع فولاد کویر می‌باشد.<br>
                    محصولات فوق با نشان کویر عرضه می گردد.<br>
                    بعد از صدور فاکتور چنانچه وزن ارسالی با وزن پیش فاکتور متفاوت بود، مابه التفاوت حساب از طریق کیف پول تسویه می شود.
                @endif
            </td>
        </tr>
    </table>

</body>
</html>
