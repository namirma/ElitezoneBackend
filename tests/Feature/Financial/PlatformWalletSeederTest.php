<?php

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Database\Seeders\PlatformWalletUserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سیدرِ حساب‌های کیف‌پولیِ پلتفرم (WSSG و WVAT).
 *
 * این سیدر **اولین** موردِ `DatabaseSeeder` است، پس هر شکستی در آن کلِ
 * `php artisan db:seed` را می‌خواباند — یعنی یک clone تازه یا یک سرور جدید
 * اصلاً به مرحلهٔ دادهٔ اولیه نمی‌رسد. دو تله‌ای که این تست پین می‌کند هر دو
 * واقعاً رخ داده بودند و هیچ‌کدام روی ماشینِ توسعه دیده نمی‌شدند، چون اسکیمای
 * آن دیتابیس با مایگریشن‌ها واگرا شده بود.
 *
 * از مهاجرتِ 2026_08_08_100100 به بعد `users.type_id` عمداً nullable است و NULL
 * معنیِ «هیچ نقشِ محصولی ندارد» را می‌دهد — که برای یک حسابِ سیستمی دقیقاً درست
 * است. پس تلهٔ اول شکلش عوض شده، نه اینکه حل شده باشد: قبلاً سیدر به‌خاطر NOT
 * NULL می‌افتاد، حالا باید مطمئن شویم NULL را عمداً می‌نویسد و نه اینکه یک
 * UserTypeِ ساختگی («مشتری») را دوباره زنده کند.
 */
class PlatformWalletSeederTest extends TestCase
{
    use DatabaseTransactions;

    /** شناسه‌هایی که قطعاً آزادند، چون سیدر شناسه را صریح ست می‌کند. */
    private function freeIds(): array
    {
        $base = (int) (User::max(User::ID) ?? 0) + 5000;

        return [$base, $base + 1];
    }

    #[Test]
    public function it_creates_both_platform_accounts_when_the_vat_wallet_is_separate(): void
    {
        // تلهٔ اول: حسابِ پلتفرم یک کاربرِ سیستمی است، نه یکی از چهار نقشِ محصول.
        // `type_id` باید NULL بماند — اگر روزی دوباره یک UserTypeِ ساختگی اینجا
        // نشانده شود، آن حساب در هر کوئریِ نقش‌محور به‌عنوان کاربرِ واقعی ظاهر می‌شود.
        //
        // تلهٔ دوم: روی `user_profiles.national_code` ایندکس UNIQUE هست و رشتهٔ خالی
        // یک مقدارِ واقعی است — پس حساب دوم با `Duplicate entry ''` می‌افتاد.
        [$systemId, $vatId] = $this->freeIds();

        config(['financial.system_user_id' => $systemId, 'financial.vat_user_id' => $vatId]);

        (new PlatformWalletUserSeeder)->run();

        foreach ([$systemId, $vatId] as $id) {
            $this->assertTrue(User::whereKey($id)->exists(), "حساب پلتفرم #{$id} ساخته نشد.");
            $this->assertNotNull(
                Wallet::where(Wallet::USER_ID, $id)->first(),
                "کیف پول حساب #{$id} ساخته نشد."
            );
            $this->assertNull(
                User::find($id)->type_id,
                "حساب پلتفرم #{$id} نباید UserType داشته باشد — حسابِ سیستمی است، نه یکی از چهار نقشِ محصول."
            );
        }
    }

    #[Test]
    public function the_accounts_carry_no_national_code(): void
    {
        // یک کد ملیِ خالی می‌توانست در ProcessPodiumDepositAction به این حساب‌ها
        // تطبیق داده شود؛ این حساب‌ها واقعاً کد ملی ندارند، پس مقدار درست NULL است.
        [$systemId, $vatId] = $this->freeIds();

        config(['financial.system_user_id' => $systemId, 'financial.vat_user_id' => $vatId]);

        (new PlatformWalletUserSeeder)->run();

        foreach ([$systemId, $vatId] as $id) {
            $profile = UserProfile::where(UserProfile::USER_ID, $id)->first();
            $this->assertNotNull($profile);
            $this->assertNull(
                $profile->national_code,
                "کد ملیِ حساب #{$id} باید NULL باشد، نه رشتهٔ خالی."
            );
        }
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        // روی هر deploy اجرا می‌شود؛ باید idempotent بماند.
        [$systemId, $vatId] = $this->freeIds();

        config(['financial.system_user_id' => $systemId, 'financial.vat_user_id' => $vatId]);

        (new PlatformWalletUserSeeder)->run();
        (new PlatformWalletUserSeeder)->run();

        $this->assertSame(1, Wallet::where(Wallet::USER_ID, $systemId)->count());
        $this->assertSame(1, Wallet::where(Wallet::USER_ID, $vatId)->count());
    }

    #[Test]
    public function a_shared_vat_wallet_creates_only_one_account(): void
    {
        // وقتی FINANCIAL_VAT_USER_ID ست نشده باشد، WVAT همان WSSG است.
        [$systemId] = $this->freeIds();

        config(['financial.system_user_id' => $systemId, 'financial.vat_user_id' => $systemId]);

        (new PlatformWalletUserSeeder)->run();

        $this->assertTrue(User::whereKey($systemId)->exists());
        $this->assertSame(1, Wallet::where(Wallet::USER_ID, $systemId)->count());
    }
}
