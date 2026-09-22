<?php

namespace App\Services;

use RuntimeException;

/**
 * saze90.com جواب نداد (قطعی، مدارِ باز، پاسخِ ناسالم) یا ادغام با
 * `SAZE90_BADGE_ENABLED=false` خاموش است.
 *
 * از «عضو نیست» جداست: «نمی‌دانیم» نباید به «نه» ترجمه شود. با سیاستِ
 * «تأیید فقط از راهِ سازه ۹۰»، ترجمه‌ی غلطش یعنی یک قطعیِ چند دقیقه‌ای همه‌ی
 * الیت‌یارها را از سفارش‌ها و دعوت‌ها می‌انداخت. `SyncUserBadgeAction` این
 * را می‌گیرد و آخرین وضعیتِ شناخته‌شده را نگه می‌دارد.
 */
final class Saze90Unavailable extends RuntimeException {}
