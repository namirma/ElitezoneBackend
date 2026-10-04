<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\User;
use App\Services\DashboardAlertsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هشدارِ «کالا تحویل شد. لطفا نظر خود را ثبت کنید.» فقط وقتی نظرسنجی روشن است.
 *
 * مدیریت نظرسنجیِ سفارش را ۱۴۰۵/۰۶/۲۲ «رسماً بسته» کرد (پاسخِ ۱-۶)؛ فلگ
 * `features.order_surveys` پیش‌فرض خاموش است و روتِ ثبتِ نظر اصلاً ساخته نمی‌شود.
 * ولی داشبورد برای هر سفارشِ تحویل‌شده همان هشدار را می‌ساخت — هشداری که چون راهی
 * برای «completed» کردنش نیست، هرگز پاک نمی‌شد.
 */
class SurveyAlertFollowsFeatureFlagTest extends TestCase
{
    use DatabaseTransactions;

    private function deliveredOrderFor(User $buyer): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'SRV-'.Str::random(10),
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        return $order->fresh();
    }

    private function surveyAlerts(User $user): array
    {
        return array_values(array_filter(
            app(DashboardAlertsService::class)->collectAlerts($user),
            fn (array $a) => str_starts_with($a['id'], 'order_survey'),
        ));
    }

    #[Test]
    public function no_survey_alert_while_surveys_are_closed(): void
    {
        config(['features.order_surveys' => false]);
        $buyer = User::factory()->create();
        $this->deliveredOrderFor($buyer);

        $this->assertSame([], $this->surveyAlerts($buyer));
    }

    #[Test]
    public function the_alert_comes_back_if_surveys_are_ever_reopened(): void
    {
        config(['features.order_surveys' => true]);
        $buyer = User::factory()->create();
        $order = $this->deliveredOrderFor($buyer);

        $this->assertSame(['order_survey_'.$order->id], array_column($this->surveyAlerts($buyer), 'id'));
    }
}
