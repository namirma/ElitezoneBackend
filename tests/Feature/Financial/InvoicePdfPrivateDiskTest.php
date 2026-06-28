<?php

namespace Tests\Feature\Financial;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invoice-PDF relocation migration must move archived invoices from the public disk
 * to the private `financial` disk (so they are no longer URL-reachable), preserving the
 * relative path, and be idempotent.
 */
class InvoicePdfPrivateDiskTest extends TestCase
{
    #[Test]
    public function the_migration_moves_invoice_pdfs_from_public_to_private(): void
    {
        Storage::fake('public');
        Storage::fake('financial');

        Storage::disk('public')->put('invoices/INV-1_123.pdf', 'PDF-BYTES');

        $migration = require database_path('migrations/2026_06_15_160000_move_invoice_pdfs_to_private_disk.php');
        $migration->up();

        Storage::disk('financial')->assertExists('invoices/INV-1_123.pdf');
        Storage::disk('public')->assertMissing('invoices/INV-1_123.pdf');
        $this->assertSame('PDF-BYTES', Storage::disk('financial')->get('invoices/INV-1_123.pdf'));

        // Idempotent: a second run is a no-op, file stays on the private disk.
        $migration->up();
        Storage::disk('financial')->assertExists('invoices/INV-1_123.pdf');
    }
}
