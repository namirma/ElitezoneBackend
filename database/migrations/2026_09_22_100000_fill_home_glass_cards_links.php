<?php

use App\Models\PageBlock;
use Illuminate\Database\Migrations\Migration;

/**
 * سه کارتِ شیشه‌ایِ هوم («برای الیت‌یارها / کارفرماها / تأمین‌کنندگان») از
 * `c527901` (۱۴۰۵/۰۶/۱۴) دکمه‌شان را فقط با `link.to` رندر می‌کنند — قاعده‌ی
 * صداقت، دکمه‌ی بی‌مقصد نه — ولی `PageBlocksSeeder` هیچ‌وقت لینکی نکاشته بود.
 * نتیجه: هفده روز بدونِ دکمه، روی هر دیتابیسی که از آن seeder آمده (dev و
 * استیجینگ). seeder با `firstOrCreate` ردیفِ موجود را عوض نمی‌کند، پس این
 * مهاجرت همان مقصدها را روی ردیف‌های موجود می‌نشاند.
 *
 * فقط لینکِ خالی پر می‌شود و فقط وقتی متنِ دکمه (`cta`) همان متنِ seeder است —
 * کارتی که ادمین خودش نوشته یا لینک داده دست نمی‌خورد. ادمین بعداً از
 * صفحه‌ساز (بک‌آفیس › محتوا › صفحه‌ساز › کارت‌های شیشه‌ای › «لینکِ دکمه»)
 * مقصد را عوض می‌کند.
 */
return new class extends Migration
{
    /** متنِ دکمه‌ی seeder → مقصد. */
    public const LINKS = [
        'شبکه خود را بسازید' => '/role',
        'خرید خود را آغاز کنید' => '/rebar',
        'درخواست تأمین کالا بدهید' => '/role',
    ];

    public function up(): void
    {
        foreach (PageBlock::where(PageBlock::TYPE, 'glass_cards')->get() as $block) {
            $data = $block->{PageBlock::DATA};
            $changed = false;

            foreach ($data['cards'] ?? [] as $i => $card) {
                $cta = $card['cta'] ?? null;
                if (! empty($card['link']['to']) || ! isset(self::LINKS[$cta])) {
                    continue;
                }
                $data['cards'][$i]['link'] = ['label' => $cta, 'to' => self::LINKS[$cta]];
                $changed = true;
            }

            if ($changed) {
                // از راهِ مدل، تا `PageBlock::booted()` کشِ صفحه را خالی کند.
                $block->{PageBlock::DATA} = $data;
                $block->save();
            }
        }
    }

    public function down(): void
    {
        // لینک‌ها را خالی نمی‌کنیم: ممکن است ادمین بعد از این مهاجرت همان مقصد را
        // آگاهانه نگه داشته باشد؛ برداشتنش دوباره دکمه‌ها را پنهان می‌کرد.
    }
};
