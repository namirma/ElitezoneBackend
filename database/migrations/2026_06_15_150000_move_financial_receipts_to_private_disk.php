<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Data migration: move already-uploaded financial receipts off the web-exposed
 * `public` disk onto the private `financial` disk.
 *
 * Wallet/supplier/order receipts were stored on the `public` disk, which is symlinked
 * into public/storage and therefore reachable at /storage/<path> WITHOUT auth (only the
 * random filename provided obscurity). New uploads now go to the private `financial`
 * disk; this migration relocates the historical files so they are no longer URL-reachable.
 *
 * The stored relative paths (e.g. "wallet_receipts/abc.jpg") are unchanged — only the
 * disk root differs — so the download endpoints keep resolving them. Idempotent: skips
 * files already present on the target. No-op when the source directories are empty
 * (fresh installs / test DB).
 */
return new class extends Migration
{
    private array $dirs = ['wallet_receipts', 'supplier_receipts', 'order_receipts'];

    public function up(): void
    {
        $this->move('public', 'financial');
    }

    public function down(): void
    {
        $this->move('financial', 'public');
    }

    private function move(string $fromDisk, string $toDisk): void
    {
        $from = Storage::disk($fromDisk);
        $to = Storage::disk($toDisk);

        foreach ($this->dirs as $dir) {
            if (! $from->exists($dir)) {
                continue;
            }

            foreach ($from->allFiles($dir) as $path) {
                if ($to->exists($path)) {
                    $from->delete($path); // already relocated on a prior run

                    continue;
                }

                $to->put($path, $from->get($path));
                $from->delete($path);
            }
        }
    }
};
