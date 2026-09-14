<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderExtensionOffer;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تمدیدِ رزرو با پیشنهادِ تأمین‌کننده و پاسخِ خریدار — پرسش ۳ مدیریت (۲۰۲۶-۰۹-۰۹).
 *
 * «رزروِ اول را هیچ‌کس نمی‌تواند کنسل کند. پس از پایانش، تمدید را تأمین‌کننده
 * پیشنهاد و خریدار می‌پذیرد یا رد می‌کند؛ ردِ هرکدام = کنسل.»
 */
class ReserveExtensionOfferTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(InvoiceGenerator::class)->shouldReceive('generateCancellationDocs')->andReturnNull();

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // آستانه‌ی «در حالِ پایان» — سه ساعت، مثلِ پیش‌فرضِ config/sales.php.
        config(['sales.reserve_warning_threshold_minutes' => 180]);
    }

    /** رزروی با مهلتِ داده‌شده، ۵ ساعت ارفاق، بدونِ بیعانه‌ی واقعی (پول این‌جا موضوع نیست). */
    private function reserved(\DateTimeInterface $deadline): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'EXT-'.Str::random(10),
            Order::STATUS => Order::STATUS_RESERVED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => 1_000_000,
        ]);

        $order->forceFill([
            Order::RESERVE_DEADLINE => $deadline,
            Order::RESERVE_DEADLINE_ORIGINAL => $deadline,
            Order::RESERVE_GRACE_HOURS => 5,
            Order::PAYMENT_TO_RESERVE => 0,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);

        return $order->fresh();
    }

    private function propose(Order $order, int $hours = 24)
    {
        Sanctum::actingAs($this->supplier);

        return $this->postJson("/api/profile/orders/{$order->id}/extension", ['hours' => $hours]);
    }

    // ── پیشنهاد ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_supplier_can_propose_once_the_deadline_has_passed(): void
    {
        $order = $this->reserved(now()->subHour());

        $this->propose($order)->assertCreated()
            ->assertJsonPath('data.extension.awaiting_me', false)
            ->assertJsonPath('data.extension.hours', 24);

        $offer = OrderExtensionOffer::first();
        $this->assertTrue($offer->isPending());
        // مهلت گذشته بود → مبنا «همین حالا» است، نه مهلتِ گذشته.
        $this->assertTrue($offer->{OrderExtensionOffer::PROPOSED_UNTIL}->greaterThan(now()->addHours(23)));

        // پیامک + زنگوله به پرداخت‌کننده، نه به تأمین‌کننده.
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->buyer->id)->count());
        $this->assertSame(0, UserNotification::where(UserNotification::USER_ID, $this->supplier->id)->count());
    }

    #[Test]
    public function the_supplier_can_propose_while_the_reserve_is_ending(): void
    {
        // دو ساعت مانده و آستانه سه ساعت است → «در حالِ پایان».
        $order = $this->reserved(now()->addHours(2));

        $this->propose($order, 10)->assertCreated();

        // مبنا مهلتِ فعلی است (هنوز نگذشته) — نه «همین حالا».
        $until = OrderExtensionOffer::first()->{OrderExtensionOffer::PROPOSED_UNTIL};
        $this->assertTrue($until->between(now()->addHours(11)->subMinute(), now()->addHours(12)->addMinute()));
    }

    #[Test]
    public function a_reserve_with_plenty_of_time_left_cannot_be_extended_yet(): void
    {
        $order = $this->reserved(now()->addDay());

        $this->propose($order)->assertStatus(422);
        $this->assertSame(0, OrderExtensionOffer::count());
    }

    #[Test]
    public function only_one_pending_offer_per_order(): void
    {
        $order = $this->reserved(now()->subHour());

        $this->propose($order)->assertCreated();
        $this->propose($order)->assertStatus(409);
    }

    #[Test]
    public function hours_are_validated_as_a_form_request(): void
    {
        $order = $this->reserved(now()->subHour());

        $this->propose($order, 0)->assertStatus(422)->assertJsonValidationErrors('hours');
        $this->propose($order, 721)->assertStatus(422)->assertJsonValidationErrors('hours');
    }

    #[Test]
    public function the_buyer_cannot_propose(): void
    {
        $order = $this->reserved(now()->subHour());
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/profile/orders/{$order->id}/extension", ['hours' => 24])->assertForbidden();
    }

    // ── پاسخِ خریدار ───────────────────────────────────────────────────────

    #[Test]
    public function accepting_moves_the_deadline_and_keeps_the_original(): void
    {
        $original = now()->subHour()->startOfSecond();
        $order = $this->reserved($original);
        $this->propose($order, 24)->assertCreated();
        $offer = OrderExtensionOffer::first();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status_info.key', Order::STATUS_RESERVED)
            ->assertJsonMissingPath('data.extension');

        $fresh = $order->fresh();
        $this->assertSame(
            $offer->{OrderExtensionOffer::PROPOSED_UNTIL}->toIso8601String(),
            $fresh->{Order::RESERVE_DEADLINE}->toIso8601String(),
        );
        $this->assertSame($original->toIso8601String(), $fresh->{Order::RESERVE_DEADLINE_ORIGINAL}->toIso8601String());
        $this->assertSame(OrderExtensionOffer::STATUS_ACCEPTED, $offer->fresh()->{OrderExtensionOffer::STATUS});

        // تأمین‌کننده خبر می‌گیرد.
        $this->assertSame(1, UserNotification::where(UserNotification::USER_ID, $this->supplier->id)->count());
    }

    #[Test]
    public function rejecting_cancels_the_reservation(): void
    {
        $order = $this->reserved(now()->subHour());
        $this->propose($order)->assertCreated();
        $offer = OrderExtensionOffer::first();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status_info.key', Order::STATUS_CANCELLED);

        $this->assertSame(OrderExtensionOffer::STATUS_REJECTED, $offer->fresh()->{OrderExtensionOffer::STATUS});
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function the_supplier_cannot_answer_their_own_offer(): void
    {
        $order = $this->reserved(now()->subHour());
        $this->propose($order)->assertCreated();
        $offer = OrderExtensionOffer::first();

        // هنوز به‌عنوانِ تأمین‌کننده لاگین است.
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/accept")->assertForbidden();
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/reject")->assertForbidden();
    }

    #[Test]
    public function an_answered_offer_cannot_be_answered_again(): void
    {
        $order = $this->reserved(now()->subHour());
        $this->propose($order)->assertCreated();
        $offer = OrderExtensionOffer::first();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/accept")->assertOk();
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/reject")->assertStatus(409);

        $this->assertSame(Order::STATUS_RESERVED, $order->fresh()->{Order::STATUS});
    }

    #[Test]
    public function after_an_accepted_extension_the_supplier_can_propose_again(): void
    {
        // «با توافق طرفین، دفعات و مدت نامحدود.»
        $order = $this->reserved(now()->subHour());
        $this->propose($order, 1)->assertCreated();
        $offer = OrderExtensionOffer::first();

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/profile/orders/{$order->id}/extension/{$offer->id}/accept")->assertOk();

        // مهلتِ تازه یک ساعت دیگر است → داخلِ آستانه‌ی «در حالِ پایان».
        $this->propose($order->fresh(), 48)->assertCreated();
        $this->assertSame(2, OrderExtensionOffer::count());
    }

    // ── سریالایز ───────────────────────────────────────────────────────────

    #[Test]
    public function the_resource_tells_each_side_where_it_stands(): void
    {
        $order = $this->reserved(now()->subHour());

        Sanctum::actingAs($this->supplier);
        $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.reservation.can_propose_extension', true)
            ->assertJsonPath('data.reservation.grace_hours', 5)
            ->assertJsonMissingPath('data.extension');

        $this->propose($order)->assertCreated();

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.extension.awaiting_me', true)
            ->assertJsonPath('data.reservation.can_propose_extension', false);

        Sanctum::actingAs($this->supplier);
        $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.extension.awaiting_me', false)
            // یک پیشنهاد باز است → پیشنهادِ دوم ممکن نیست.
            ->assertJsonPath('data.reservation.can_propose_extension', false);
    }
}
