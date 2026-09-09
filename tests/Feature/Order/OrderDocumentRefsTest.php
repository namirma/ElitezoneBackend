<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * شماره‌های سند در `OrderResource.financial.refs`.
 *
 * هیچ‌کدام از این هفت ستون سریالایز نمی‌شد، پس تب‌های «اسناد مالی» بک‌آفیس — که
 * دقیقاً برای نشان‌دادنشان ساخته شده‌اند — فقط فهرستی از شماره‌ی سفارش بودند و
 * تیم فرانت ستون‌های همیشه-`—` را از پنل برداشت.
 *
 * ⚠️ گیت اینجا صرفاً نمایشی نیست. `partyRolesFor()` یک‌بار ذی‌نفع را الیت‌یار
 * حساب می‌کرد و نتیجه‌اش این بود که **کارفرما فاکتور مودیانِ شخصیِ مشاورش را
 * می‌گرفت**. این تست همان مرز را روی این بلوک هم می‌بندد: سندِ هر طرف فقط به
 * خودش و به ادمین.
 */
class OrderDocumentRefsTest extends TestCase
{
    use DatabaseTransactions;

    private const REFS = [
        Order::INITIAL_INVOICE_REF => 'INV-INIT-1',
        Order::FINAL_INVOICE_REF => 'INV-FINAL-1',
        Order::SYSTEM_MOADIAN_REF => 'SYS-MOADIAN-1',
        Order::WAYBILL_REF => 'WAYBILL-1',
        Order::SUPPLIER_MOADIAN_REF => 'SUP-MOADIAN-1',
        Order::ELITE_YAR_MOADIAN_REF => 'EY-MOADIAN-1',
    ];

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name], ['display_name' => ucfirst($name)]);
    }

    /** یک سفارش با هر هفت شماره‌ی سند پر. */
    private function makeOrder(User $buyer, User $supplier, User $eliteYar): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ELITE_YAR_USER_ID => $eliteYar->id,
            Order::ORDER_NUMBER => 'REFS-'.uniqid(),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        // ستون‌های اسنپ‌شات fillable نیستند.
        $order->forceFill(self::REFS)->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
        ]);

        return $order->fresh();
    }

    /** @return array<string, mixed> */
    private function refsSeenBy(User $viewer, Order $order): array
    {
        Sanctum::actingAs($viewer);

        return $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->json('data.financial.refs') ?? [];
    }

    #[Test]
    public function an_admin_sees_every_document_reference(): void
    {
        $admin = User::factory()->create();
        $admin->addRole($this->role('admin'));

        $order = $this->makeOrder(User::factory()->create(), User::factory()->create(), User::factory()->create());

        $refs = $this->refsSeenBy($admin, $order);

        foreach (self::REFS as $column => $value) {
            $this->assertSame($value, $refs[$column] ?? null, "ادمین باید {$column} را ببیند.");
        }
    }

    #[Test]
    public function the_buyer_never_sees_the_elite_yars_personal_tax_invoice(): void
    {
        $buyer = User::factory()->create();
        $order = $this->makeOrder($buyer, User::factory()->create(), User::factory()->create());

        $refs = $this->refsSeenBy($buyer, $order);

        // سندهای خودش می‌آیند …
        $this->assertSame('INV-INIT-1', $refs[Order::INITIAL_INVOICE_REF] ?? null);
        $this->assertSame('SYS-MOADIAN-1', $refs[Order::SYSTEM_MOADIAN_REF] ?? null);

        // … ولی فاکتور مودیانِ الیت‌یار سندِ **شخصیِ** اوست، به مبلغ کمیسیونش.
        $this->assertArrayNotHasKey(Order::ELITE_YAR_MOADIAN_REF, $refs);
    }

    #[Test]
    public function the_supplier_sees_their_own_documents_and_not_the_buyers(): void
    {
        $supplier = User::factory()->create();
        $supplier->addRole($this->role('supplier'));

        $order = $this->makeOrder(User::factory()->create(), $supplier, User::factory()->create());

        $refs = $this->refsSeenBy($supplier, $order);

        $this->assertSame('SUP-MOADIAN-1', $refs[Order::SUPPLIER_MOADIAN_REF] ?? null);
        // بارنامه سندِ مشترکِ حمل است و برای تأمین‌کننده هم لازم.
        $this->assertSame('WAYBILL-1', $refs[Order::WAYBILL_REF] ?? null);

        $this->assertArrayNotHasKey(Order::INITIAL_INVOICE_REF, $refs);
        $this->assertArrayNotHasKey(Order::SYSTEM_MOADIAN_REF, $refs);
        $this->assertArrayNotHasKey(Order::ELITE_YAR_MOADIAN_REF, $refs);
    }

    #[Test]
    public function the_elite_yar_sees_their_own_moadian_invoice(): void
    {
        $eliteYar = User::factory()->create();
        $order = $this->makeOrder(User::factory()->create(), User::factory()->create(), $eliteYar);

        $refs = $this->refsSeenBy($eliteYar, $order);

        $this->assertSame('EY-MOADIAN-1', $refs[Order::ELITE_YAR_MOADIAN_REF] ?? null);
    }

    #[Test]
    public function a_reference_that_is_null_is_omitted_rather_than_sent_as_null(): void
    {
        $admin = User::factory()->create();
        $admin->addRole($this->role('admin'));

        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'REFS-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        $refs = $this->refsSeenBy($admin, $order->fresh());

        // یک سفارشِ تازه هیچ سندی ندارد — کلیدِ `null` فقط جدول را شلوغ می‌کند.
        $this->assertSame([], $refs);
    }
}
