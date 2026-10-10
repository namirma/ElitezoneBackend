<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * واحدِ پولِ پیامک‌ها (BE3، علی ۱۴۰۵/۰۷/۱۹): «:amount ریال» → «:amount :unit» در ردیف‌های `sms_templates`.
 *
 * `syncFromLang` متنِ ردیفِ موجود را هرگز بازنویسی نمی‌کند، پس متنِ تازه‌ی `lang/fa/sms.php` فقط با همین
 * مهاجرت به سرور می‌رسد. فقط همین زیرعبارت‌ها عوض می‌شوند — بقیه‌ی متنی که ادمین ویرایش کرده دست نمی‌خورد.
 * با واحدِ پیش‌فرض (ریال) متنِ ارسالی عیناً همان قبلی است. `unit` به فهرستِ جانگهدارهای مجازِ همان ردیف
 * اضافه می‌شود، وگرنه ادمین نمی‌توانست متن را از پنل دوباره ذخیره کند. `down()` دقیقاً برمی‌گرداند.
 *
 * ردیفی که کلمه‌ی واحد را به شکلِ دیگری نوشته (مثلاً «ریال» جدا از `:amount`) دست نمی‌خورد و عددِ ریالی
 * می‌گیرد (`SmsTemplate::withMoneyUnit` فقط وقتی متن `:unit` دارد تبدیل می‌کند) — کلیدش در لاگ می‌آید.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const REPLACE = [
        ':amount ریال' => ':amount :unit',
        ':balance ریال' => ':balance :unit',
    ];

    /** واریزِ پادیوم پیش از BE3 مبلغ را تومان می‌فرستاد؛ حالا ریال + `:unit`. */
    private const PODIUM_KEY = 'wallet_deposited_podium';

    public function up(): void
    {
        if (! Schema::hasTable('sms_templates')) {
            return;
        }

        foreach (DB::table('sms_templates')->get(['id', 'key', 'body', 'placeholders']) as $row) {
            $map = self::REPLACE + ($row->key === self::PODIUM_KEY ? [':amount تومان' => ':amount :unit'] : []);
            $body = strtr((string) $row->body, $map);

            if ($body === $row->body) {
                if (preg_match('/:(amount|balance)\b/u', (string) $row->body) && ! str_contains((string) $row->body, ':unit')) {
                    logger()->warning("SMS money-unit migration: {$row->key} left untouched (no «:amount ریال» in its text)");
                }

                continue;
            }

            $placeholders = json_decode((string) ($row->placeholders ?? '[]'), true) ?: [];
            if (! in_array('unit', $placeholders, true)) {
                $placeholders[] = 'unit';
            }

            DB::table('sms_templates')->where('id', $row->id)->update([
                'body' => $body,
                'placeholders' => json_encode(array_values($placeholders), JSON_UNESCAPED_UNICODE),
            ]);
        }

        Cache::forget('sms_templates:by_key');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sms_templates')) {
            return;
        }

        foreach (DB::table('sms_templates')->where('body', 'like', '%:unit%')->get(['id', 'key', 'body', 'placeholders']) as $row) {
            $map = $row->key === self::PODIUM_KEY
                ? [':amount :unit' => ':amount تومان']
                : array_flip(self::REPLACE);

            $placeholders = array_values(array_diff(json_decode((string) ($row->placeholders ?? '[]'), true) ?: [], ['unit']));

            DB::table('sms_templates')->where('id', $row->id)->update([
                'body' => strtr((string) $row->body, $map),
                'placeholders' => json_encode($placeholders, JSON_UNESCAPED_UNICODE),
            ]);
        }

        Cache::forget('sms_templates:by_key');
    }
};
