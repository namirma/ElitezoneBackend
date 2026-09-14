<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; padding: 24px; background: #f5f6f8; color: #1f2937; font-family: Tahoma, "Segoe UI", sans-serif; }
        .card { max-width: 420px; margin: 40px auto; background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 1px 4px rgba(0, 0, 0, .08); }
        h1 { font-size: 18px; margin: 0 0 12px; }
        p { font-size: 14px; line-height: 1.9; margin: 0 0 20px; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; background: #0a69d6; color: #fff; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>
<main class="card">
    <h1>{{ $title }}</h1>
    @if ($body)
        <p>{{ $body }}</p>
    @endif
    @if ($action)
        {{-- POST به همان URLِ امضاشده؛ GET هیچ‌چیزی ثبت نمی‌کند (routes/api/public.php). --}}
        <form method="post" action="{{ $action }}">
            <button type="submit">{{ $button }}</button>
        </form>
    @endif
</main>
</body>
</html>
