<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds the five platform badges AND publishes their artwork to the public disk.
 *
 * Why both in one place: BadgeResource serialises the icon as
 * Storage::disk('public')->url($icon) (e.g. /storage/badges/gold.png). For the
 * frontend to actually render it, two things must be true together — the DB row's
 * `icon` must point at a real file, and that file must exist on the public disk.
 * Seeding the row without publishing the file (the previous state) is exactly why
 * the frontend saw broken images.
 *
 * The artwork is version-controlled under database/seeders/assets/badges/ so a
 * fresh checkout (e.g. the frontend dev's local copy) gets the files on pull and
 * this seeder copies them into storage/app/public/badges/.
 *
 * Codes MUST stay in sync with SyncUserBadgeAction::MANAGED_CODES
 * (gold / silver / bronze / saze90 / pioneer) — a code the action can resolve but
 * that has no Badge row here never reaches the API response.
 */
class BadgeSeeder extends Seeder
{
    /** @var list<array{name:string,code:string,file:string}> */
    private const BADGES = [
        ['name' => 'طلایی', 'code' => 'gold', 'file' => 'gold.png'],
        ['name' => 'نقره‌ای', 'code' => 'silver', 'file' => 'silver.png'],
        ['name' => 'برنزی', 'code' => 'bronze', 'file' => 'bronze.png'],
        ['name' => 'پیشگام', 'code' => 'pioneer', 'file' => 'pioneer.png'],
        ['name' => 'کاربر سازه ۹۰', 'code' => 'saze90', 'file' => 'saze90.png'],
    ];

    public function run(): void
    {
        $this->publishIcons();

        foreach (self::BADGES as $badge) {
            Badge::updateOrCreate(
                [Badge::CODE => $badge['code']],
                [
                    Badge::NAME => $badge['name'],
                    Badge::ICON => 'badges/'.$badge['file'],
                ],
            );
        }
    }

    /**
     * Copy the in-repo badge artwork onto the public disk. Idempotent — overwrites
     * with the canonical source each run. Missing source files are skipped with a
     * warning instead of crashing the whole seed.
     */
    private function publishIcons(): void
    {
        $disk = Storage::disk('public');

        foreach (self::BADGES as $badge) {
            $source = database_path('seeders/assets/badges/'.$badge['file']);

            if (! File::exists($source)) {
                $this->command?->warn("Badge artwork missing, skipped: {$source}");

                continue;
            }

            $disk->put('badges/'.$badge['file'], File::get($source));
        }
    }
}
