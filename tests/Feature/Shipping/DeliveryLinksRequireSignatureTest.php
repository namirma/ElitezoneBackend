<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * لینک‌های پیامکیِ «رسیدم» و «تحویل گرفتم» فقط با امضای معتبر کار می‌کنند.
 *
 * هر دو مسیر عمومی‌اند و کنترلرشان **هیچ** بررسی‌ای نمی‌کند؛ تنها گارد middlewareِ
 * `signed` در `routes/api/public.php` است. تأییدِ تحویل روی تسویه‌ی مالیِ سفارش اثر
 * دارد، پس اگر روزی `signed` از آن گروه برداشته شود، هر کسی با شمردنِ شناسه‌ها
 * می‌تواند تحویلِ هر محموله‌ای را ثبت کند — و تا این تست نبود، هیچ چیز قرمز نمی‌شد.
 */
class DeliveryLinksRequireSignatureTest extends TestCase
{
    use DatabaseTransactions;

    private function shipment(): Shipment
    {
        return Shipment::create([
            Shipment::ORDER_ID => Order::factory()->create()->id,
            Shipment::STATUS => Shipment::STATUS_SHIPPED,
        ]);
    }

    #[Test]
    public function an_unsigned_delivery_confirmation_is_refused_and_changes_nothing(): void
    {
        Queue::fake();
        $shipment = $this->shipment();

        $this->getJson("/api/delivery/{$shipment->id}/confirm")->assertForbidden();
        $this->getJson("/api/shipments/{$shipment->id}/arrived")->assertForbidden();

        $this->assertSame(Shipment::STATUS_SHIPPED, $shipment->fresh()->{Shipment::STATUS});
    }

    #[Test]
    public function a_tampered_or_expired_signature_is_refused(): void
    {
        Queue::fake();
        $shipment = $this->shipment();

        $signed = URL::temporarySignedRoute('delivery.confirm', now()->addDay(), ['shipment' => $shipment->id]);
        $this->getJson(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $signed))
            ->assertForbidden();

        // لینکِ امضای **شناسه‌ی دیگری** نباید روی این محموله کار کند.
        $other = URL::temporarySignedRoute('delivery.confirm', now()->addDay(), ['shipment' => $shipment->id + 1]);
        $this->getJson(str_replace('/delivery/'.($shipment->id + 1).'/', "/delivery/{$shipment->id}/", $other))
            ->assertForbidden();

        $expired = URL::temporarySignedRoute('delivery.confirm', now()->subMinute(), ['shipment' => $shipment->id]);
        $this->getJson($expired)->assertForbidden();

        $this->assertSame(Shipment::STATUS_SHIPPED, $shipment->fresh()->{Shipment::STATUS});
    }
}
