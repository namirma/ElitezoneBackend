<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\FundWalletAction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Financial receipts must land on the private `financial` disk, never on the
 * web-exposed `public` disk (which is symlinked into /storage and reachable
 * without auth).
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class ReceiptPrivateDiskTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function deposit_receipt_is_stored_on_the_private_disk_not_public(): void
    {
        Storage::fake('financial');
        Storage::fake('public');

        $user = User::factory()->create();

        $tx = app(FundWalletAction::class)->execute(
            user: $user,
            amount: 1_000_000,
            paymentMethod: WalletTransaction::METHOD_BANK_TRANSFER,
            description: 'test deposit',
            receiptFile: UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        );

        $path = $tx->meta['receipt_path'] ?? null;
        $this->assertNotNull($path);

        Storage::disk('financial')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
}
