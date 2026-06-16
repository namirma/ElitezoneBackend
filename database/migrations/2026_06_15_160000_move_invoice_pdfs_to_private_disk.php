<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Data migration: move archived invoice PDFs off the web-exposed `public` disk onto the
 * private `financial` disk.
 *
 * InvoicePdfRenderer used to `Storage::disk('public')->put("invoices/…")`, which is
 * symlinked into public/storage and therefore reachable at /storage/invoices/… without
 * auth (only the filename's timestamp gave obscurity). These files are an archive only —
 * the download endpoint regenerates the PDF on the fly and never reads them — so moving
 * them is safe and changes no API.
 *
 * Relative paths ("invoices/<n>_<ts>.pdf") are preserved (only the disk root changes), so
 * `invoices.file_path` stays valid. Idempotent; no-op on fresh installs / test DB.
 */
return new class extends Migration
{
    private string $dir = 'invoices';

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

        if (! $from->exists($this->dir)) {
            return;
        }

        foreach ($from->allFiles($this->dir) as $path) {
            if ($to->exists($path)) {
                $from->delete($path); // already relocated on a prior run

                continue;
            }

            $to->put($path, $from->get($path));
            $from->delete($path);
        }
    }
};
