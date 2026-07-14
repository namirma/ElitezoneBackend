<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-document authorization on OrderInvoiceController::download.
 *
 * A buyer's documents (initial / final / tax) must NOT be downloadable by other
 * parties on the order (supplier, eliteYar) even though they pass the generic
 * `view` policy — only the buyer (placer) or an admin may fetch them.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class InvoiceAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $eliteYar;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->eliteYar = User::factory()->create();

        $supplierRole = Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier', 'description' => 'Supplier']);
        $this->supplier = User::factory()->create();
        $this->supplier->addRole($supplierRole);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->eliteYar->id,
            Order::ORDER_NUMBER => 'INV-'.now()->format('YmdHis').rand(100, 999),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => 'paid',
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);
    }

    private function url(string $type = 'tax'): string
    {
        return "/api/profile/orders/{$this->order->id}/invoice/{$type}";
    }

    /** @test */
    public function consultant_cannot_download_the_buyers_tax_invoice(): void
    {
        Sanctum::actingAs($this->eliteYar);
        $this->get($this->url('tax'))->assertForbidden();
    }

    /** @test */
    public function supplier_cannot_download_the_buyers_tax_invoice(): void
    {
        Sanctum::actingAs($this->supplier);
        $this->get($this->url('tax'))->assertForbidden();
    }

    /** @test */
    public function buyer_can_download_their_own_tax_invoice(): void
    {
        // Mock the generator so no real PDF/Blade view is needed.
        $pdf = \Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('PDF-BYTES', 200, ['Content-Type' => 'application/pdf']));
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('download')
            ->andReturn($pdf);

        Sanctum::actingAs($this->buyer);
        $this->get($this->url('tax'))->assertOk();
    }
}
