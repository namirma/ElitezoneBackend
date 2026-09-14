<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $agreement->title }}</title>
    <style>
        body { font-family: sans-serif; direction: rtl; font-size: 11pt; color: #1a1a1a; line-height: 1.9; }
        .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 14px; }
        h1 { font-size: 14pt; margin: 0 0 6px 0; }
        .meta { font-size: 9pt; color: #555; }
        .meta span { margin-left: 14px; }
        .content { white-space: pre-wrap; text-align: justify; }
        .checkboxes { margin-top: 16px; border: 1px solid #999; padding: 10px 14px; }
        .checkboxes h2 { font-size: 11pt; margin: 0 0 8px 0; }
        .checkboxes li { margin-bottom: 5px; font-size: 10pt; }
        .receipt { margin-top: 18px; border: 1px dashed #666; padding: 10px 14px; background: #f7f7f7; }
        .receipt h2 { font-size: 11pt; margin: 0 0 8px 0; }
        .receipt td { font-size: 9pt; padding: 2px 6px; }
        .hash { font-size: 8pt; color: #777; word-break: break-all; direction: ltr; text-align: left; }
        .footer { margin-top: 14px; font-size: 8pt; color: #888; border-top: 1px solid #ccc; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $agreement->title }}</h1>
        <div class="meta">
            <span>نسخه: {{ $agreement->version }}</span>
            @if ($agreement->effective_at)
                <span>تاریخ لازم‌الاجرا: {{ \App\Support\JalaliDate::format($agreement->effective_at, 'Y/m/d') }}</span>
            @endif
            <span>مالک پلتفرم: شرکت نرم‌افزاری سازه اسپادانا — elitezone.me</span>
        </div>
    </div>

    <div class="content">{{ $agreement->content }}</div>

    @if (!empty($agreement->checkboxes))
        <div class="checkboxes">
            <h2>موارد پذیرش الزامی</h2>
            <ol>
                @foreach ($agreement->checkboxes as $box)
                    <li><strong>{{ $box['code'] ?? '' }}</strong> — {{ $box['text'] ?? '' }}</li>
                @endforeach
            </ol>
        </div>
    @endif

    @if ($acceptance)
        <div class="receipt">
            <h2>رسید پذیرش الکترونیکی</h2>
            <table>
                <tr>
                    <td>کد رهگیری پذیرش:</td><td>{{ $acceptance->id ?? '—' }}</td>
                    <td>نسخه پذیرفته‌شده:</td><td>{{ $acceptance->version }}</td>
                </tr>
                <tr>
                    <td>تاریخ و ساعت پذیرش:</td>
                    <td>{{ $acceptance->accepted_at ? \App\Support\JalaliDate::format($acceptance->accepted_at, 'Y/m/d H:i:s') : '—' }}</td>
                    <td>روش پذیرش:</td><td>{{ $acceptance->acceptance_method ?? '—' }}</td>
                </tr>
            </table>
            <div class="hash">SHA-256: {{ $acceptance->content_sha256 }}</div>
        </div>
    @endif

    <div class="footer">
        این سند به‌صورت الکترونیکی در پلتفرم الیت‌زون تولید شده است.
        <span class="hash">SHA-256 نسخه: {{ $agreement->content_sha256 }}</span>
    </div>
</body>
</html>
