<?php

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * قیمت‌ها باید نامِ واریانت را برگردانند، نه فقط id.
 *
 * ProductPriceResource هیچ‌وقت بلاکِ `variant` را نداشت، در حالی که هر سه
 * مصرف‌کننده — دو جدولِ پنلِ سوپلایر و PricesPage بک‌آفیس — `r.variant?.name`
 * را می‌خوانند. نتیجه جدولی بود که به‌جای نامِ کالا «#254» نشان می‌داد.
 *
 * شکستِ خاموشی که این تست می‌گیرد حذفِ eager loadِ `productVariant` است: آن‌وقت
 * whenLoaded کلید را بی‌صدا برمی‌دارد و دوباره id لخت به کاربر می‌رسد. index
 * مسیرِ `->with()` را می‌سنجد و store مسیرِ `->load()` را.
 */
class SupplierPriceVariantNameTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private function makeSupplier(): User
    {
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        return $supplier;
    }

    private function makeSupplierFactory(User $supplier): Factory
    {
        return Factory::create([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه تست',
            Factory::SLUG => 'factory-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ]);
    }

    #[Test]
    public function the_prices_index_names_the_variant(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant(null, ['name' => 'میلگرد ۱۶ آجدار']);
        $this->makePrice($variant, $factory, $location);

        Sanctum::actingAs($supplier);

        $rows = $this->getJson('/api/profile/supplier/prices')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('product_variant_id', $variant->id);

        $this->assertNotNull($row, 'قیمتِ ساخته‌شده در فهرست نبود.');
        $this->assertSame('میلگرد ۱۶ آجدار', $row['variant']['name']);
    }

    #[Test]
    public function creating_a_price_names_the_variant_in_the_response(): void
    {
        $supplier = $this->makeSupplier();
        $factory = $this->makeSupplierFactory($supplier);
        $location = $this->makeLocation($factory);
        $variant = $this->makeVariant(null, ['name' => 'ورق گالوانیزه ۲ میل']);

        Sanctum::actingAs($supplier);

        $this->postJson('/api/profile/supplier/prices', [
            'product_variant_id' => $variant->id,
            'factory_id' => $factory->id,
            'factory_location_id' => $location->id,
            'general_price' => 1_000_000,
            'special_price' => 900_000,
            'golden_figure' => 950_000,
        ])->assertCreated()
            ->assertJsonPath('data.variant.name', 'ورق گالوانیزه ۲ میل');
    }
}
