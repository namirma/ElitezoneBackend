<?php

namespace App\Sms;

/**
 * معنیِ فارسی و نمونه‌ی هر `:placeholder` که در قالب‌های پیامک به کار می‌رود.
 *
 * تا پیش از این ادمین فقط نامِ جانگهدار را می‌دید (`:until`، `:code`) و باید حدس
 * می‌زد چه چیزی جایش می‌نشیند. این فهرست یک منبع برای هر سه مصرف‌کننده است:
 * Resource قالب، endpointِ رویدادها، و راهنمای فرم در پنل.
 *
 * یک نام می‌تواند در دو کلید دو معنی داشته باشد (`code` = کد ورود یا کد پیگیری
 * تیکت؛ `amount` در واریزِ پادیوم به تومان است) — `PER_KEY` همان استثناها را
 * روی یک کلیدِ مشخص جایگزین می‌کند. نامی که هیچ‌جا نیست، خودش را برمی‌گرداند
 * تا پنل خالی نماند (و تستِ کامل‌بودنِ فهرست همان را قرمز می‌کند).
 */
final class PlaceholderCatalog
{
    /** @var array<string, array{0: string, 1: string}> name => [meaning, example] */
    private const GENERAL = [
        'code' => ['کد یک‌بارمصرفِ تأیید (شش رقم)', '482913'],
        'name' => ['نامِ کامل کاربر؛ اگر نام ندارد، شماره‌ی موبایلش', 'علی کاظمی'],
        'employer_name' => ['نامِ کارفرمای دعوت‌کننده', 'شرکت سازه‌گستر'],
        'advisor_name' => ['نامِ الیت‌یارِ دعوت‌کننده', 'علی کاظمی'],
        'elite_yar_name' => ['نامِ الیت‌یاری که سبد را برای کارفرما بسته', 'علی کاظمی'],
        'type' => ['نوعِ تراکنش کیف پول: «واریز»، «برداشت» یا «تراکنش»', 'واریز'],
        'amount' => ['مبلغ به ریال، با جداکننده‌ی هزارگان', '1,200,000'],
        'order_number' => ['شماره‌ی سفارش', 'EZ-ORD-RB-140502-70300100'],
        'order' => ['شماره‌ی سفارش', 'EZ-ORD-RB-140502-70300100'],
        'minutes' => ['دقیقه‌های مانده تا انقضای سبد', '30'],
        'hours' => ['مهلت به ساعت', '48'],
        'link' => ['لینکِ امضاشده‌ی یک‌بارمصرف (کوتاه، با اعتبارِ محدود)', 'https://elitezone.me/d/…'],
        'until' => ['تاریخ و ساعتِ پایانِ مهلت، شمسی و به وقت تهران', '۱۴۰۵/۰۶/۲۵ ۱۸:۳۰'],
        'respond_until' => ['آخرین مهلتِ پاسخ، شمسی و به وقت تهران', '۱۴۰۵/۰۶/۲۴ ۱۲:۰۰'],
        'reason' => ['دلیلی که طرفِ مقابل یا پشتیبانی نوشته', 'قیمت تغییر کرده است'],
        'variant' => ['نامِ کاملِ واریانتِ محصول', 'میلگرد آجدار ۱۴ ذوب‌آهن'],
        'subject' => ['موضوعِ درخواستِ کاتالوگ (نامِ محصول یا واریانت)', 'میلگرد آجدار ۱۶'],
        'proposer' => ['نامِ سازنده‌ی کوپن که تأمینِ تخفیف را پیشنهاد داده', 'ادمین الیت‌زون'],
        'funder' => ['نامِ تأمین‌کننده‌ی سهمِ تخفیف', 'کارخانه‌ی فولاد کویر'],
        'percent' => ['سهمِ تخفیف به درصد', '5'],
        'tracking_line' => ['یا خالی، یا «کد پیگیری: …» در یک خطِ جدا', "\nکد پیگیری: 8231"],
        'old_segment' => ['بخشِ قبلیِ مشتری در تحلیل RFM', 'loyal'],
        'new_segment' => ['بخشِ جدیدِ مشتری در تحلیل RFM', 'at_risk'],
        'status' => ['وضعیتِ تراکنش پس از تصمیم ادمین', 'confirmed'],
        'panel_url' => ['نشانیِ پنلِ تأمین‌کننده (config services.supplier_panel.url)', 'https://panel.elitezone.me'],
    ];

    /** @var array<string, array<string, array{0: string, 1: string}>> key => name => [meaning, example] */
    private const PER_KEY = [
        'loginMessage' => ['code' => ['کد ورود (شش رقم)', '482913']],
        'driver_verify' => ['code' => ['کد تأیید شماره‌ی راننده (شش رقم)', '482913']],
        'beneficiary_verification' => [
            'code' => ['کد تأیید ذی‌نفع (شش رقم)', '482913'],
            'name' => ['نامِ الیت‌یاری که می‌خواهد به نامِ کارفرما خرید کند', 'علی کاظمی'],
        ],
        'ticket_admin_replied' => ['code' => ['کد پیگیری تیکت', 'TCK-10482']],
        'ticket_resolved' => ['code' => ['کد پیگیری تیکت', 'TCK-10482']],
        'ticket_closed' => ['code' => ['کد پیگیری تیکت', 'TCK-10482']],
        'wallet_deposited_podium' => ['amount' => ['مبلغ به تومان (نه ریال) — پادیوم به تومان گزارش می‌دهد', '120,000']],
        'cart_expiring' => ['minutes' => ['دقیقه‌های مانده تا انقضای سبدِ پارک‌شده (از تنظیماتِ فروش)', '30']],
        'delivery_expiring' => ['hours' => ['ساعت‌های مانده تا مهلتِ تأیید تحویل', '2']],
        'price_change_expired' => ['hours' => ['مهلتِ بررسیِ تغییر قیمت به ساعت (تنظیماتِ فروش)', '48']],
        'coupon_funding_requested' => ['code' => ['کدِ کوپن', 'SPRING1405']],
        'coupon_funding_accepted' => ['code' => ['کدِ کوپن', 'SPRING1405']],
        'coupon_funding_declined' => ['code' => ['کدِ کوپن', 'SPRING1405']],
    ];

    /**
     * @param  list<string>  $names
     * @return list<array{name: string, meaning: string, example: string}>
     */
    public static function describe(array $names, ?string $key = null): array
    {
        $out = [];

        foreach ($names as $name) {
            [$meaning, $example] = self::PER_KEY[$key][$name] ?? self::GENERAL[$name] ?? [$name, ''];
            $out[] = ['name' => $name, 'meaning' => $meaning, 'example' => $example];
        }

        return $out;
    }

    public static function knows(string $name): bool
    {
        return array_key_exists($name, self::GENERAL);
    }
}
