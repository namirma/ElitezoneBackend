<?php

namespace Tests\Concerns;

use App\Models\Setting;
use App\Services\Profile\EliteYARVerificationMethods;

/**
 * سیاستِ لانچ (۱۴۰۵/۰۶/۳۰) فقط سازه ۹۰ را روشن می‌کارد؛ تستی که مسیرِ مدارکِ
 * تحصیلی/سوابقِ شغلی را می‌زند باید آن روش را صریحاً روشن کند — همان‌طور که
 * ادمین از تنظیمات می‌کند. `Setting::saved` کش را خودش خالی می‌کند.
 */
trait EnablesVerificationMethods
{
    protected function enableVerificationMethod(string $method): void
    {
        Setting::updateOrCreate(
            [Setting::KEY => EliteYARVerificationMethods::KEYS[$method]],
            [Setting::VALUE => '1']
        );
    }
}
