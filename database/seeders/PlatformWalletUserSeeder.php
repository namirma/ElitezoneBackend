<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * حساب‌های کیف‌پولیِ خودِ پلتفرم را تضمین می‌کند.
 *
 * دو کیف پول از پنج کیف پول سیستم مالک انسانی ندارند:
 *   • WSSG (`financial.system_user_id`) — کیف پول «سازه»، سهم پلتفرم
 *   • WVAT (`financial.vat_user_id`)    — امانیِ مالیات بر ارزش افزوده
 *
 * این‌ها را `.env` با **شناسه‌ی عددی** نگه می‌دارد، در حالی که شناسه‌ها با هر بار
 * seed شدنِ دیتابیس جابه‌جا می‌شوند. نتیجه‌ی این ناهم‌خوانی سکوت نیست — هر
 * پرداختی در سیستم با نقض کلید خارجی (`wallets.user_id`) می‌شکند، آن هم داخل
 * تراکنشِ پرداخت، و مشتری فقط «خطا در پردازش پرداخت» می‌بیند. تا پیش از این
 * سیدر، فقط `DevSeeder` این حساب را می‌ساخت و از توسعه‌دهنده می‌خواست خودش
 * `.env` را دستی به‌روز کند — یعنی یک قدمِ دستیِ فراموش‌شدنی بین «دیتابیس تازه»
 * و «پرداختِ کارکن».
 *
 * این سیدر عمداً حسابِ **همان شناسه‌ای** را می‌سازد که کانفیگ می‌گوید، تا داده و
 * کانفیگ نتوانند از هم جدا شوند. idempotent است و روی هر محیطی قابل اجراست.
 *
 * این حساب‌ها عمداً قابل ورود نیستند: رمز تصادفی و دورانداخته می‌شود و موبایل
 * از بازه‌ی رزروشده‌ی `0990000000x` است که هرگز به کاربر واقعی داده نمی‌شود.
 */
class PlatformWalletUserSeeder extends Seeder
{
    public function run(): void
    {
        $systemId = (int) config('financial.system_user_id', 1);
        $vatId = (int) config('financial.vat_user_id', $systemId);

        $this->ensure($systemId, '0990000001', 'سازه', 'پلتفرم');

        // وقتی کیف پول مالیات جدا نشده باشد، همان حساب سیستم است — دوباره نساز.
        if ($vatId !== $systemId) {
            $this->ensure($vatId, '0990000002', 'امانی', 'مالیات');
        }
    }

    private function ensure(int $id, string $mobile, string $name, string $family): void
    {
        if (User::whereKey($id)->exists()) {
            Wallet::firstOrCreate([Wallet::USER_ID => $id]);
            $this->command?->info("  ✓ حساب پلتفرم #{$id} از قبل موجود است");

            return;
        }

        // شناسه صریح ست می‌شود: کانفیگ می‌گوید «کیف پول پلتفرم کاربر شماره N است»،
        // پس همان N باید ساخته شود — نه هر شناسه‌ای که auto-increment بدهد.
        $user = new User;
        $user->id = $id;
        $user->mobile = $mobile;
        $user->password = Hash::make(Str::random(48));
        $user->is_active = true;
        // کیف‌پولِ پلتفرم یک حسابِ سیستمی است و هیچ نقشِ محصولی ندارد. `type_id`
        // از مهاجرتِ 2026_08_08_100100 به بعد nullable است، پس NULL همان چیزی را
        // می‌گوید که واقعیت دارد. قبلاً اینجا یک UserTypeِ ساختگی («مشتری») ساخته
        // می‌شد چون ستون NOT NULL بود و این سیدر — که اولین موردِ DatabaseSeeder
        // است — وگرنه کلِ seed را می‌خواباند.
        $user->type_id = null;
        $user->save();

        UserProfile::firstOrCreate(
            [UserProfile::USER_ID => $user->id],
            [
                UserProfile::NAME => $name,
                UserProfile::FAMILY => $family,
                // NULL نه `''` — روی `user_profiles.national_code` ایندکس UNIQUE هست و
                // رشته‌ی خالی یک مقدارِ واقعی شمرده می‌شود، پس حسابِ دومِ پلتفرم (WVAT،
                // وقتی از WSSG جدا باشد) با `Duplicate entry ''` می‌افتاد. همان حفره‌ای
                // که مایگریشنِ 2026_07_26_120000 برای ادمین‌ها بست؛ این حساب‌ها هم
                // واقعاً کد ملی ندارند.
                UserProfile::NATIONAL_CODE => null,
            ]
        );

        Wallet::firstOrCreate([Wallet::USER_ID => $user->id]);

        $this->command?->warn("  + حساب پلتفرم #{$id} ساخته شد ({$name} {$family})");
    }
}
