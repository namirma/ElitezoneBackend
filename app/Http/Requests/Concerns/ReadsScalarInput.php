<?php

namespace App\Http\Requests\Concerns;

/**
 * خواندنِ ورودیِ خام به‌عنوانِ رشته، پیش از اعتبارسنجی.
 *
 * `(string) $this->input('x')` روی مقداری که کلاینت به‌شکلِ آرایه فرستاده
 * (`{"x":["a"]}`) هشدارِ «Array to string conversion» می‌دهد که هندلرِ لاراول به
 * استثنا تبدیل می‌کند — یعنی ۵۰۰ به‌جای ۴۲۲. `trim()`/`strtoupper()` روی آرایه هم
 * TypeError است. این‌جا مقدارِ غیراسکالر به پیش‌فرض می‌افتد تا قاعده‌های
 * `required`/`string` خودشان با یک ۴۲۲ معمولی جوابش بدهند.
 * پین: `Security/ValidationNeverFiveHundredTest`.
 */
trait ReadsScalarInput
{
    protected function scalarInput(string $key, string $default = ''): string
    {
        return self::scalar($this->input($key), $default);
    }

    protected static function scalar(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
