<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Actions\Product\FilterProductsAction;
use App\DataTransferObjects\Product\ProductFilterData;
use App\Http\Resources\Product\ProductVariantResource;
use App\Models\PriceSetting;
use App\Models\ProductVariant;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Pricing\VariantPriceHydrator;
use App\Services\PricingRoleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مدیریت، دورِ پنجم، پرسشِ ۱ (۱۴۰۵/۰۷/۱۴): تخفیفِ خریدارِ دارای الیت‌یار با عنوانِ کلیِ «تخفیف» روی
 * **کارتِ محصول**. کارت از فهرستِ محصولات (`FilterProductsAction`) و صفحه‌ی محصول از
 * `VariantPriceHydrator` ساخته می‌شود؛ هر دو باید درصد را به ردیفِ قیمتِ `ProductVariantResource` برسانند.
 * پیش از این، درصد فقط در دو endpointِ قیمتِ تکی (`/products/variant-price`, `/calculate-price`) بود.
 */
class BuyerDiscountOnProductCardTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private function pricedVariant(): ProductVariant
    {
        Cache::flush();
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_TC_PERCENTAGE], [PriceSetting::NAME => 'tc', PriceSetting::VALUE => '1']);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_OW_SHARE], [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '40']);

        // فهرست فقط محصولِ تأمین‌کننده‌ای را نشان می‌دهد که پنجره‌ی قیمتِ معتبر دارد (`reserve_time`).
        $supplier = User::factory()->create();
        SupplierProfile::create([SupplierProfile::USER_ID => $supplier->id])
            ->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED, SupplierProfile::RESERVE_TIME => now()->addDays(3)])
            ->save();
        $factory = $this->makeFactory();
        $factory->forceFill(['supplier_user_id' => $supplier->id])->save();
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant(null, ['stock_quantity' => 100]);
        $this->makePrice($variant, $factory, $location, general: 1_000_000, special: 900_000);

        return $variant;
    }

    private function listedPrice(ProductVariant $variant, string $role): mixed
    {
        $page = app(FilterProductsAction::class)->execute(new ProductFilterData(
            search: null,
            categoryId: (int) $variant->product->category_id,
            sortKey: 'newest',
            roleCode: $role,
            filterCategories: null,
            filterValues: null,
            debugPrices: false,
            perPage: 20,
            availability: null,
            withVat: true,
        ));

        $product = collect($page->items())->firstWhere('id', $variant->product_id);
        $this->assertNotNull($product, 'محصولِ تست باید در فهرست باشد.');

        return $product->variants->firstWhere('id', $variant->id)->prices->first();
    }

    #[Test]
    public function the_listing_card_carries_the_buyer_discount_percent_only_for_the_with_elite_yar_group(): void
    {
        $variant = $this->pricedVariant();

        // %TC سراسری ۱٪ × سهمِ OW ۴۰٪ = ۰٫۴٪
        $this->assertEqualsWithDelta(0.4, (float) $this->listedPrice($variant, PricingRoleResolver::WITH_ELITE_YAR)->buyer_discount_percent, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $this->listedPrice($variant, PricingRoleResolver::WITHOUT_ELITE_YAR)->buyer_discount_percent, 0.0001);
    }

    #[Test]
    public function the_product_page_row_serializes_the_buyer_discount_percent(): void
    {
        $variant = $this->pricedVariant()->load(['prices.factory', 'prices.factoryLocation']);
        app(VariantPriceHydrator::class)->hydrate($variant, PricingRoleResolver::WITH_ELITE_YAR);

        $row = collect((new ProductVariantResource($variant))->toArray(request())['prices'] ?? [])->first();

        $this->assertNotNull($row);
        $this->assertSame('0.4', $row['buyer_discount_percent']);
    }
}
