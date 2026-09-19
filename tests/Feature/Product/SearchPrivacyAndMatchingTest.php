<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Search;
use App\Models\SupplierActivityCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/search` — دو باگ که هر دو بی‌صدا بودند.
 */
class SearchPrivacyAndMatchingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * «آخرین جستجوهای شما» نباید جستجوی کاربرانِ دیگر را به مهمان نشان بدهد.
     *
     * ⚠️ `/search` عمومی است و فیلترِ `user_id` داخلِ `if (Auth::check())` بود،
     * پس برای مهمان **هیچ** فیلتری اعمال نمی‌شد و ۵ عبارتِ آخرِ کلِ سیستم
     * برمی‌گشت — عبارت‌هایی که کاربرانِ دیگر تایپ کرده بودند، زیر عنوانِ
     * «آخرین جستجوهای شما».
     */
    #[Test]
    public function a_guest_never_sees_another_users_search_history(): void
    {
        $other = User::factory()->create();

        Search::create([
            Search::QUERY => 'یک عبارتِ خصوصیِ کاربرِ دیگر',
            Search::RESULTS_COUNT => 0,
            Search::USER_ID => $other->id,
            Search::IP_ADDRESS => '10.0.0.0',
            Search::USER_AGENT => 'phpunit',
            Search::CREATED_AT => now(),
        ]);

        $body = $this->getJson('/api/search?query='.urlencode('میلگرد'))->assertOk()->json();

        $latest = data_get($body, 'data.latest_searches', data_get($body, 'latest_searches', []));

        $this->assertNotContains(
            'یک عبارتِ خصوصیِ کاربرِ دیگر',
            (array) $latest,
            'مهمان نباید تاریخچه‌ی جستجوی کاربرِ دیگر را ببیند.'
        );
    }

    /** کاربرِ لاگین‌کرده تاریخچه‌ی خودش را می‌بیند (رفتارِ درست نباید بشکند). */
    #[Test]
    public function an_authenticated_user_still_sees_their_own_history(): void
    {
        $user = User::factory()->create();

        Search::create([
            Search::QUERY => 'جستجوی خودم',
            Search::RESULTS_COUNT => 0,
            Search::USER_ID => $user->id,
            Search::IP_ADDRESS => '10.0.0.0',
            Search::USER_AGENT => 'phpunit',
            Search::CREATED_AT => now(),
        ]);

        Sanctum::actingAs($user);

        $body = $this->getJson('/api/search?query='.urlencode('میلگرد'))->assertOk()->json();
        $latest = (array) data_get($body, 'data.latest_searches', data_get($body, 'latest_searches', []));

        $this->assertContains('جستجوی خودم', $latest);
    }

    /**
     * تطبیقِ نام باید مثل SQL بی‌تفاوت به بزرگی/کوچکیِ حروف باشد.
     *
     * ⚠️ کوئریِ `LIKE` روی collationِ `..._ci` بی‌تفاوت است، ولی فیلترِ بعدیِ PHP
     * `Str::contains()` بود که **حساس** است. پس ردیف از دیتابیس برمی‌گشت و
     * بلافاصله در PHP دور ریخته می‌شد: نتیجه صفر، و `results_count` هم صفر
     * لاگ می‌شد — یعنی داده‌ی آنالیتیکس هم با همان باگ خراب می‌شد.
     */
    #[Test]
    public function a_latin_category_matches_regardless_of_letter_case(): void
    {
        SupplierActivityCategory::create(['name' => 'Steel Structures']);

        $body = $this->getJson('/api/search?query=steel')->assertOk()->json();
        $related = (array) data_get($body, 'data.related_searches', data_get($body, 'related_searches', []));

        $this->assertContains('Steel Structures', $related);
    }

    /**
     * لاگِ جستجو باید سیاستِ نگهداشت داشته باشد.
     *
     * ⚠️ `/api/search` عمومی است و به‌ازای هر درخواست یک ردیف می‌نویسد (عبارت،
     * `user_id`، IP، User-Agent). برای `product_views` و سبدِ مهمان سقفِ نگهداشت
     * تعریف شده بود ولی `searches` هیچ کرونی نداشت: هم جدول بی‌نهایت رشد
     * می‌کرد، هم تاریخچه‌ی جستجوی افراد برای همیشه می‌ماند.
     */
    #[Test]
    public function old_search_logs_are_pruned_and_recent_ones_are_kept(): void
    {
        $user = User::factory()->create();

        $old = Search::create([
            Search::QUERY => 'جستجوی خیلی قدیمی',
            Search::RESULTS_COUNT => 0,
            Search::USER_ID => $user->id,
            Search::IP_ADDRESS => '10.0.0.0',
            Search::USER_AGENT => 'phpunit',
        ]);
        // `created_at` در `$fillable` نیست و Eloquent خودش می‌نویسد، پس سن را
        // بعد از ساخت عقب می‌بریم.
        $old->forceFill([Search::CREATED_AT => now()->subDays(200)])->save();

        $recent = Search::create([
            Search::QUERY => 'جستجوی تازه',
            Search::RESULTS_COUNT => 0,
            Search::USER_ID => $user->id,
            Search::IP_ADDRESS => '10.0.0.0',
            Search::USER_AGENT => 'phpunit',
        ]);

        $this->artisan('searches:prune')->assertExitCode(0);

        $this->assertNull($old->fresh(), 'لاگِ قدیمی باید حذف شود.');
        $this->assertNotNull($recent->fresh(), 'لاگِ تازه نباید حذف شود.');
    }
}
