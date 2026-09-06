<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ProductVariant;

/**
 * «آیا این مبدأ مالِ من است؟» برای **یک دسته ردیف**، با دو کوئری.
 *
 * قاعده‌ی مالکیت از قبل در چهار کنترلرِ تأمین‌کننده به‌صورت closure rule تکرار
 * شده بود — و آن شکل برای ثبتِ تک‌ردیفی درست کار می‌کند ولی برای آپلودِ گروهی
 * فاجعه است: هر ردیف دو کوئریِ مستقل می‌زند، پس یک فهرستِ ۵۰۰ ردیفی هزار رفت‌وبرگشت
 * می‌شود.
 *
 * اینجا همه‌ی شناسه‌ها یک‌بار خوانده می‌شوند و بررسی در حافظه انجام می‌شود.
 * خروجی **خطا به‌ازای شماره‌ی ردیف** است، چون در آپلودِ گروهی «یکی از ردیف‌ها
 * غلط است» بی‌فایده است؛ کاربر باید بداند کدام سطرِ فایلش.
 */
final class SupplierOriginGuard
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string> اندیسِ ردیف ← پیامِ خطا
     */
    public function validate(array $rows, int $supplierUserId): array
    {
        $factoryIds = [];
        $locationIds = [];
        $variantIds = [];

        foreach ($rows as $row) {
            $factoryIds[] = (int) ($row['factory_id'] ?? 0);
            $locationIds[] = (int) ($row['factory_location_id'] ?? 0);
            $variantIds[] = (int) ($row['product_variant_id'] ?? 0);
        }

        $ownedFactories = Factory::query()
            ->whereIn(Factory::ID, array_unique($factoryIds))
            ->where(Factory::SUPPLIER_USER_ID, $supplierUserId)
            ->pluck(Factory::ID)
            ->all();

        // ‏`factory_id` روی خودِ مکان خوانده می‌شود تا تطبیقِ مکان↔کارخانه هم
        // بدونِ کوئریِ اضافه ممکن باشد.
        $locationFactory = FactoryLocation::query()
            ->whereIn(FactoryLocation::ID, array_unique($locationIds))
            ->pluck(FactoryLocation::FACTORY_ID, FactoryLocation::ID)
            ->all();

        $knownVariants = ProductVariant::query()
            ->whereIn(ProductVariant::ID, array_unique($variantIds))
            ->pluck(ProductVariant::ID)
            ->all();

        $errors = [];

        foreach ($rows as $index => $row) {
            $factoryId = (int) ($row['factory_id'] ?? 0);
            $locationId = (int) ($row['factory_location_id'] ?? 0);
            $variantId = (int) ($row['product_variant_id'] ?? 0);

            if (! in_array($variantId, $knownVariants, true)) {
                $errors[$index] = __('supplier.bulk.unknown_variant');

                continue;
            }

            if (! in_array($factoryId, $ownedFactories, true)) {
                $errors[$index] = __('auth.policy.factory_access_denied');

                continue;
            }

            $ownerFactoryOfLocation = $locationFactory[$locationId] ?? null;

            if ($ownerFactoryOfLocation === null) {
                $errors[$index] = __('auth.policy.factory_access_denied');

                continue;
            }

            // مکان باید هم مالِ همین تأمین‌کننده باشد و هم زیرِ همان کارخانه‌ای
            // که در ردیف آمده — وگرنه قیمت روی مبدأیی می‌نشیند که وجود ندارد.
            if (! in_array((int) $ownerFactoryOfLocation, $ownedFactories, true)) {
                $errors[$index] = __('auth.policy.factory_access_denied');

                continue;
            }

            if ((int) $ownerFactoryOfLocation !== $factoryId) {
                $errors[$index] = __('admin.prices.location_factory_mismatch');
            }
        }

        return $errors;
    }
}
