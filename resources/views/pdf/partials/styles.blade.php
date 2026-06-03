    <style>
        /* ============================================================
           mPDF Bulletproof Stylesheet - 11-Column Mathematical Grid
           ============================================================ */
        * { box-sizing: border-box; }

        body {
            font-family: yekanbakh, "Yekan Bakh FaNum", Tahoma, sans-serif;
            direction: rtl;
            color: #000;
            margin: 0;
            padding: 0;
            font-size: 7.5pt;
        }

        /* 1. Brand Logos */
        table.brand { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.brand td { border: none; padding: 0 2px; vertical-align: middle; }
        td.brand-web  { text-align: right; }
        td.brand-logo { text-align: left; }

        /* 2. Master Grid */
        table.master-grid {
            width: 100%;
            border-collapse: collapse;
            border: 0.75pt solid #000; /* کادر بیرونی */
            table-layout: fixed;
        }
        table.master-grid td, table.master-grid th {
            border: 0.3pt solid #000;
            vertical-align: middle;
            padding: 6px 4px;   /* ↑ از 3.5px/3px — فاصله عمودی بیشتر بین ردیف‌ها */
        }

        /* کلاس‌های کمکی */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .nowrap { white-space: nowrap; overflow: hidden; }

        /* رنگ‌بندی */
        .bg-gray { background-color: #E9ECEF; }
        .bg-pink { background-color: #D60A69; color: #ffffff; }
        .bg-white { background-color: #ffffff; }
        .text-red { color: #FF0000; }
        .text-muted { color: #A6A6A6; }

        /* سایز فونت‌ها و فواصل */
        .title-main { font-size: 20pt; font-weight: bold; }
        .section-title { font-size: 10pt; font-weight: bold; text-align: center; background-color: #E9ECEF; padding: 7px 0; }

        /* لیبل‌ها */
        .lbl { font-size: 7.5pt; font-weight: normal; background-color: #fcfcfc; }
        .val { font-size: 7.5pt; }

        /* هدر اقلام */
        .items-head td { text-align: center; font-size: 7.5pt; font-weight: normal; padding: 5px 3px; }

        .amount-cell { font-size: 7.5pt; white-space: nowrap; text-align: center; }

        .notes-text { line-height: 1.8; font-size: 7.5pt; padding: 10px 12px !important; }
    </style>
