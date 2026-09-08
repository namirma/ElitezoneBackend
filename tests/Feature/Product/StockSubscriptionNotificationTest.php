<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\ProductVariant;
use App\Models\StockSubscription;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * اشتراکِ «خبرم کن موجود شد» باید واقعاً خبر بدهد.
 *
 * ⚠️ **این قابلیت وجود داشت و هرگز کار نمی‌کرد.** جدولِ `stock_subscriptions`،
 * ستونِ `notified_at` و اندپوینتِ ثبتِ اشتراک همه از ابتدا بودند، ولی **هیچ
 * کدی در پروژه `notified_at` را نمی‌نوشت و هیچ اعلانی نمی‌ساخت** — نه جاب، نه
 * لیسنر، نه observer. کاربر دکمه را می‌زد، ردیف ذخیره می‌شد، و تا ابد سکوت.
 *
 * چون پاسخِ اندپوینت ۲۰۰ بود و ردیف هم واقعاً ثبت می‌شد، هیچ خطایی هیچ‌جا دیده
 * نمی‌شد؛ فقط اعلان هرگز نمی‌رسید.
 */
class StockSubscriptionNotificationTest extends TestCase
{
    use CreatesPricingFixtures, DatabaseTransactions;

    private User $user;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // این پروژه فکتوریِ Product/ProductVariant ندارد؛ تراتِ مشترکِ تست‌های
        // قیمت‌گذاری راهِ رسمیِ ساختِ گرافِ حداقلیِ محصول است.
        $this->variant = $this->makeVariant(null, ['stock_quantity' => 0]);
    }

    private function subscribe(?bool $notified = false): StockSubscription
    {
        return StockSubscription::create([
            StockSubscription::USER_ID => $this->user->id,
            StockSubscription::PRODUCT_VARIANT_ID => $this->variant->id,
            StockSubscription::NOTIFIED_AT => $notified ? now() : null,
        ]);
    }

    private function restock(int $quantity = 50): void
    {
        // عمداً از طریق query builder — همان مسیری که `FactoryStock` و بخشی از
        // `InventoryService` استفاده می‌کنند و **رویدادِ مدل نمی‌زند**. اگر روزی
        // این منطق به observer منتقل شود، این تست قرمز می‌شود؛ و باید بشود.
        ProductVariant::where('id', $this->variant->id)->update(['stock_quantity' => $quantity]);
    }

    #[Test]
    public function a_restock_notifies_the_waiting_subscriber(): void
    {
        $subscription = $this->subscribe();
        $this->restock();

        $this->artisan('stock:notify-subscribers')->assertExitCode(0);

        $this->assertNotNull(
            $subscription->fresh()->{StockSubscription::NOTIFIED_AT},
            'اشتراک باید مهرِ notified_at بخورد.'
        );

        $this->assertSame(
            1,
            UserNotification::where(UserNotification::USER_ID, $this->user->id)->count(),
            'کاربر باید دقیقاً یک اعلان بگیرد.'
        );
    }

    /** تا وقتی موجودی صفر است هیچ اعلانی نباید برود. */
    #[Test]
    public function nothing_is_sent_while_the_variant_is_still_out_of_stock(): void
    {
        $subscription = $this->subscribe();

        $this->artisan('stock:notify-subscribers')->assertExitCode(0);

        $this->assertNull($subscription->fresh()->{StockSubscription::NOTIFIED_AT});
        $this->assertSame(0, UserNotification::where(UserNotification::USER_ID, $this->user->id)->count());
    }

    /**
     * اجرای دوباره نباید اعلانِ تکراری بسازد.
     *
     * ‏`notified_at` هم فیلترِ ورودی است هم مهرِ خروجی، و هر دو در یک تراکنش
     * نوشته می‌شوند — بدون این، هر ۱۰ دقیقه یک اعلانِ تکراری تا ابد می‌رفت.
     */
    #[Test]
    public function running_twice_does_not_send_a_second_notification(): void
    {
        $this->subscribe();
        $this->restock();

        $this->artisan('stock:notify-subscribers')->assertExitCode(0);
        $this->artisan('stock:notify-subscribers')->assertExitCode(0);

        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->user->id)->count());
    }

    /**
     * دکمه‌ی اشتراک بعد از یک‌بار اعلان نباید وارونه شود.
     *
     * ‏`index()` فقط اشتراک‌های `notified_at = null` را برمی‌گرداند، پس کاربر
     * «مشترک نیستم» می‌دید؛ ولی `toggle()` ردیفِ مهرخورده را پیدا می‌کرد و
     * حذفش می‌کرد — یعنی زدنِ دکمه به قصدِ اشتراک، پاسخِ «لغو شد» می‌داد.
     */
    #[Test]
    public function re_subscribing_after_a_notification_re_arms_instead_of_unsubscribing(): void
    {
        $subscription = $this->subscribe(notified: true);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/stock-subscriptions/toggle', ['variant_id' => $this->variant->id])
            ->assertOk()
            ->assertJsonPath('data.is_subscribed', true);

        $fresh = $subscription->fresh();
        $this->assertNotNull($fresh, 'ردیف نباید حذف شود — unique(user,variant) اجازه‌ی ردیف دوم نمی‌دهد.');
        $this->assertNull($fresh->{StockSubscription::NOTIFIED_AT}, 'اشتراک باید دوباره مسلح شود.');
    }

    /** اشتراکِ فعال با زدنِ دکمه باید واقعاً لغو شود (رفتار قبلی نباید بشکند). */
    #[Test]
    public function toggling_an_active_subscription_still_unsubscribes(): void
    {
        $subscription = $this->subscribe();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/stock-subscriptions/toggle', ['variant_id' => $this->variant->id])
            ->assertOk()
            ->assertJsonPath('data.is_subscribed', false);

        $this->assertNull($subscription->fresh());
    }
}
