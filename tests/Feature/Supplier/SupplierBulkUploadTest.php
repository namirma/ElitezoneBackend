<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\Factory;
use App\Models\FactoryStock;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * آپلودِ گروهیِ قیمت و موجودی.
 *
 * تا امروز هر قیمت و هر موجودی یک POST بود. برای تأمین‌کننده‌ای با ده‌ها
 * واریانت × چند مبدأ این سقفِ عملیِ پنل بود — به‌روزرسانیِ یک فهرست یعنی صدها
 * درخواست، و هر ردیفِ تکراری هم ۴۲۲ می‌گرفت.
 *
 * سه رفتارِ کلیدی پین می‌شوند: upsert بودن، **همه‌یا‌هیچ**، و خطا به‌ازای
 * شماره‌ی ردیف.
 */
class SupplierBulkUploadTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $supplier;

    private $factory;

    private $location;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $this->factory = $this->makeFactory();
        $this->factory->forceFill([Factory::SUPPLIER_USER_ID => $this->supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);

        $this->variantA = $this->makeVariant();
        $this->variantB = $this->makeVariant();
    }

    /** @param array<string, mixed> $extra */
    private function priceRow(ProductVariant $variant, array $extra = []): array
    {
        return array_merge([
            'product_variant_id' => $variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
            'general_price' => 1_000_000,
        ], $extra);
    }

    #[Test]
    public function a_price_list_is_created_then_updated_by_re_uploading_it(): void
    {
        Sanctum::actingAs($this->supplier);

        $first = $this->postJson('/api/profile/supplier/prices/bulk', [
            'rows' => [$this->priceRow($this->variantA), $this->priceRow($this->variantB)],
        ])->assertOk()->json('data');

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $first['updated']);

        // upsert است نه insert: فرستادنِ دوباره‌ی همان فهرست باید به‌روزرسانی
        // کند، نه اینکه هر ردیف ۴۲۲ِ «تکراری» بگیرد.
        $second = $this->postJson('/api/profile/supplier/prices/bulk', [
            'rows' => [$this->priceRow($this->variantA, ['general_price' => 2_000_000])],
        ])->assertOk()->json('data');

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);

        $this->assertSame(
            2_000_000.0,
            (float) ProductPrice::query()
                ->where(ProductPrice::PRODUCT_VARIANT_ID, $this->variantA->id)
                ->value(ProductPrice::GENERAL_PRICE)
        );
    }

    #[Test]
    public function one_bad_row_rejects_the_whole_file_and_names_its_index(): void
    {
        $foreignFactory = $this->makeFactory();
        $foreignFactory->forceFill([Factory::SUPPLIER_USER_ID => User::factory()->create()->id])->save();

        Sanctum::actingAs($this->supplier);

        $response = $this->postJson('/api/profile/supplier/prices/bulk', [
            'rows' => [
                $this->priceRow($this->variantA),
                $this->priceRow($this->variantB, ['factory_id' => $foreignFactory->id]),
            ],
        ])->assertStatus(422);

        // «یکی از ردیف‌ها غلط است» در فایلِ چندصدسطری بی‌فایده است.
        $this->assertArrayHasKey(1, $response->json('errors.rows'));
        $this->assertArrayNotHasKey(0, $response->json('errors.rows'));

        // و **هیچ‌کدام** ثبت نشده: فهرستِ نیمه‌اعمال‌شده بدتر از ردشده است، چون
        // کاربر نمی‌داند کدام سطر نشست و قیمتِ نیمه‌به‌روز مستقیم روی فروش است.
        $this->assertSame(0, ProductPrice::query()
            ->whereIn(ProductPrice::PRODUCT_VARIANT_ID, [$this->variantA->id, $this->variantB->id])
            ->count());
    }

    #[Test]
    public function a_location_from_another_factory_of_mine_is_still_refused(): void
    {
        // مالکیت کافی نیست: مکان باید زیرِ همان کارخانه‌ای باشد که در ردیف آمده،
        // وگرنه قیمت روی مبدأیی می‌نشیند که وجود ندارد.
        $secondFactory = $this->makeFactory();
        $secondFactory->forceFill([Factory::SUPPLIER_USER_ID => $this->supplier->id])->save();
        $foreignLocation = $this->makeLocation($secondFactory);

        Sanctum::actingAs($this->supplier);

        $this->postJson('/api/profile/supplier/prices/bulk', [
            'rows' => [$this->priceRow($this->variantA, ['factory_location_id' => $foreignLocation->id])],
        ])->assertStatus(422);
    }

    #[Test]
    public function stocks_upload_in_bulk_and_the_variant_rollup_follows(): void
    {
        // زیرسیستمِ موجودیِ per-origin (`factory_stocks`) هنوز به برنچِ L13 پورت
        // نشده، پس آنجا نه کنترلری هست و نه روتی. همان قراردادِ بقیه‌ی تست‌ها:
        // skip به‌جای فورک‌کردنِ فایل، تا بین دو برنچ بایت‌به‌بایت یکی بماند.
        if (! class_exists(FactoryStock::class)) {
            $this->markTestSkipped('factory_stocks is not on this branch.');
        }

        Sanctum::actingAs($this->supplier);

        $this->postJson('/api/profile/supplier/stocks/bulk', [
            'rows' => [
                ['product_variant_id' => $this->variantA->id, 'factory_id' => $this->factory->id,
                    'factory_location_id' => $this->location->id, 'quantity' => 1200],
            ],
        ])->assertOk();

        $this->assertSame(1200.0, (float) FactoryStock::query()
            ->where(FactoryStock::PRODUCT_VARIANT_ID, $this->variantA->id)
            ->value(FactoryStock::QUANTITY));

        // ستونِ نمایشیِ واریانت باید مجموعِ مبدأها را نشان بدهد.
        $this->assertSame(
            1200.0,
            (float) $this->variantA->fresh()->{ProductVariant::STOCK_QUANTITY}
        );
    }

    #[Test]
    public function a_non_supplier_cannot_bulk_upload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/supplier/prices/bulk', [
            'rows' => [$this->priceRow($this->variantA)],
        ])->assertStatus(403);
    }

    #[Test]
    public function an_oversized_file_is_refused_rather_than_half_processed(): void
    {
        Sanctum::actingAs($this->supplier);

        $rows = array_fill(0, 501, $this->priceRow($this->variantA));

        $this->postJson('/api/profile/supplier/prices/bulk', ['rows' => $rows])
            ->assertStatus(422);

        $this->assertSame(0, ProductPrice::query()
            ->where(ProductPrice::PRODUCT_VARIANT_ID, $this->variantA->id)
            ->count());
    }
}
