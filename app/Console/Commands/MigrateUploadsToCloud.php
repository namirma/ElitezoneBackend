<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One-time cutover: copy EXISTING local uploads to the configured cloud (s3/Arvan)
 * disks. Run AFTER flipping FILESYSTEM_PUBLIC_DRIVER / FILESYSTEM_PRIVATE_DRIVER to
 * `s3` and filling the AWS_* credentials (see .env.cloud.example).
 *
 * It reads the old files straight from the local filesystem paths and writes them
 * to the now-cloud disks, preserving each object's relative key so existing DB
 * paths (e.g. `avatars/x.png`, `job_experiences/y.pdf`) keep resolving.
 */
class MigrateUploadsToCloud extends Command
{
    protected $signature = 'storage:migrate-to-cloud {--dry-run : List what would be copied without uploading}';

    protected $description = 'Copy existing local uploads to the configured cloud (s3/Arvan) disks';

    /**
     * disk => [scan dir on the local filesystem, base used to compute the object key].
     * For the `local` disk only the private-document subtree is migrated (not the
     * whole storage/app, which also contains public/ and financial/).
     */
    private function map(): array
    {
        return [
            'public' => ['scan' => storage_path('app/public'), 'base' => storage_path('app/public')],
            'financial' => ['scan' => storage_path('app/financial'), 'base' => storage_path('app/financial')],
            'local' => ['scan' => storage_path('app/job_experiences'), 'base' => storage_path('app')],
        ];
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($this->map() as $disk => $paths) {
            if (config("filesystems.disks.$disk.driver") !== 's3') {
                $this->warn("• disk [$disk] is not s3 — skipping. Set the matching FILESYSTEM_*_DRIVER=s3 first.");

                continue;
            }

            if (! is_dir($paths['scan'])) {
                $this->line("• disk [$disk]: nothing to migrate (no $paths[scan]).");

                continue;
            }

            $visibility = $disk === 'public' ? 'public' : 'private';

            foreach (File::allFiles($paths['scan']) as $file) {
                $key = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($paths['base']))), '/');
                if ($key === '' || str_starts_with(basename($key), '.')) {
                    continue; // skip .gitignore and dotfiles
                }

                if ($dry) {
                    $this->line("  would copy → $disk : $key");
                    $total++;

                    continue;
                }

                Storage::disk($disk)->put($key, File::get($file->getPathname()), $visibility);
                $this->line("  copied → $disk : $key");
                $total++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '').'Done. '.$total.' file(s) '.($dry ? 'pending' : 'copied').'.');

        return self::SUCCESS;
    }
}
