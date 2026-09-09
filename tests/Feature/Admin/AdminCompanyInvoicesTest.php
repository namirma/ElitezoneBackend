<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\FinancialCodes;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * `GET /admin/financial/company/invoices` — چهار تب، و دو تای‌شان همیشه خالی بود.
 *
 * ‏`type=initial` (تبِ **پیش‌فرض**) روی `orders.initial_invoice_ref` فیلتر می‌کرد و
 * `type=tax` روی `orders.tax_invoice_ref`. **هیچ‌کدام از این دو ستون نویسنده‌ای
 * ندارند** — نه اکشنی، نه سیدری، نه مهاجرتی؛ فقط در `$fillable` و در همین دو
 * شرط ظاهر می‌شوند. پس هر دو تب یک آرایه‌ی تهی برمی‌گرداندند که از «هنوز
 * فاکتوری صادر نشده» قابلِ تشخیص نبود.
 *
 * این تست **سوئیپ** است: هر چهار تب باید سفارشی که واجدِ شرایطش هست را برگردانند
 * و سفارشِ نامربوط را برنگردانند. نسخه‌ی نمونه‌ای — که فقط یک تب را می‌سنجید —
 * دقیقاً همان چیزی است که اجازه داد دوتای دیگر بی‌صدا خالی بمانند.
 */
class AdminCompanyInvoicesTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    private function makeOrder(array $snapshot = []): Order
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'INV-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        if ($snapshot !== []) {
            $order->forceFill($snapshot)->save();
        }

        return $order->fresh();
    }

    /** @return list<int> */
    private function idsFor(string $type): array
    {
        Sanctum::actingAs($this->admin);

        return collect(
            $this->getJson("/api/admin/financial/company/invoices?type={$type}")
                ->assertOk()
                ->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function every_tab_returns_the_orders_it_claims_to(): void
    {
        // یک سفارشِ نامربوط: هیچ سند و هیچ ref ندارد و نباید در هیچ تبی بیاید.
        $bare = $this->makeOrder();

        $initial = $this->makeOrder();
        Invoice::create([
            'order_id' => $initial->id,
            'user_id' => $initial->{Order::PLACER_USER_ID},
            'document_code' => FinancialCodes::DOC_RECEIPT_OWNER,
            'invoice_number' => 'A-'.uniqid(),
            'amount' => 1_000_000,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        $final = $this->makeOrder([Order::FINAL_INVOICE_REF => 'FIN-1']);
        $final->forceFill([Order::STATUS => Order::STATUS_SHIPPED])->save();

        $tax = $this->makeOrder([Order::SYSTEM_MOADIAN_REF => 'MOADIAN-1']);

        $expected = [
            'initial' => $initial->id,
            'final' => $final->id,
            'tax' => $tax->id,
        ];

        foreach ($expected as $type => $id) {
            $ids = $this->idsFor($type);

            $this->assertContains($id, $ids, "تبِ «{$type}» سفارشِ واجدِ شرایط را برنگرداند.");
            $this->assertNotContains($bare->id, $ids, "تبِ «{$type}» سفارشِ نامربوط را برگرداند.");
        }
    }

    #[Test]
    public function a_reservation_receipt_also_counts_as_an_initial_invoice(): void
    {
        // خریدِ مستقیم سند A می‌گیرد و رزرو سند E — هر دو «فاکتورِ اولیه»اند.
        $reserved = $this->makeOrder();
        Invoice::create([
            'order_id' => $reserved->id,
            'user_id' => $reserved->{Order::PLACER_USER_ID},
            'document_code' => FinancialCodes::DOC_RESERVE_RECEIPT,
            'invoice_number' => 'E-'.uniqid(),
            'amount' => 500_000,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        $this->assertContains($reserved->id, $this->idsFor('initial'));
    }

    #[Test]
    public function a_later_document_does_not_leak_into_the_initial_tab(): void
    {
        // سند K (بارنامه) هم روی همان سفارش می‌نشیند؛ «هر سفارشی که سندی دارد»
        // شرطِ درستی نیست.
        $shipped = $this->makeOrder();
        Invoice::create([
            'order_id' => $shipped->id,
            'user_id' => $shipped->{Order::PLACER_USER_ID},
            'document_code' => FinancialCodes::DOC_BILL_OF_LADING,
            'invoice_number' => 'K-'.uniqid(),
            'amount' => 0,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        $this->assertNotContains($shipped->id, $this->idsFor('initial'));
    }
}
