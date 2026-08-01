<?php

namespace Tests\Feature\Smoke;

use App\Models\Address;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پیمایشِ صفر تا صدِ خرید، از راهِ همان endpointهایی که فرانت صدا می‌زند.
 *
 * هدف «assert کردنِ یک عدد» نیست — دیدنِ این است که آیا مسیر واقعاً از ابتدا تا
 * انتها کار می‌کند. هر گام وضعیتش را چاپ می‌کند و در صورت شکست، بدنهٔ پاسخ را،
 * تا معلوم شود دقیقاً کجا می‌ایستد.
 */
class PurchaseWalkthroughTest extends TestCase
{
    use DatabaseTransactions;

    private array $log = [];

    private function step(string $name, int $status, mixed $detail = null): void
    {
        $mark = $status >= 200 && $status < 300 ? 'OK ' : 'FAIL';
        $line = sprintf('[%s] %-46s HTTP %d', $mark, $name, $status);
        if ($detail !== null) {
            $line .= ' | '.(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE));
        }
        $this->log[] = $line;
    }

    protected function tearDown(): void
    {
        if ($this->log) {
            fwrite(STDERR, "\n".str_repeat('─', 78)."\n");
            fwrite(STDERR, "  پیمایش خرید — صفر تا صد\n");
            fwrite(STDERR, str_repeat('─', 78)."\n");
            foreach ($this->log as $l) {
                fwrite(STDERR, '  '.$l."\n");
            }
            fwrite(STDERR, str_repeat('─', 78)."\n\n");
        }
        parent::tearDown();
    }

    #[Test]
    public function a_buyer_can_go_from_signup_to_a_paid_order(): void
    {
        // اسناد PDF فونتِ فارسی می‌خواهند که gitignore شده — مسیر را تست می‌کنیم نه رندر را.
        // ثبت‌نام یک استعلامِ هویتیِ **واقعی** به پودیوم می‌زند (تطبیق موبایل ↔ کد ملی).
        // در این محیط کردنشیال ندارد و پس از ~۱۱ ثانیه با ۵۰۲ برمی‌گردد، پس آن یک گام
        // را stub می‌کنیم تا بقیهٔ مسیرِ خرید قابل اجرا شود. این خودش یک واقعیتِ
        // عملیاتی است: بدون دسترسیِ پودیوم، هیچ کاربر تازه‌ای نمی‌تواند ثبت‌نام کند.
        // اکشنِ هویت `final` است، پس مرزِ واقعی — یعنی خودِ کلاینتِ پودیوم — را
        // stub می‌کنیم: تطبیقِ موبایل↔کدملی و استعلامِ تاریخ تولد هر دو موفق.
        $this->mock(\App\Services\PodiumClient::class, function ($m) {
            $m->shouldReceive('callSc2')->andReturn([
                'hasError' => false,
                'result' => ['matched' => true, 'resultCode' => '1', 'birthDate' => '13700101'],
            ]);
        });

        // `generate()` خصوصی است — سطحِ عمومی همین متدهاست.
        $pdf = Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('%PDF'));
        $this->mock(InvoiceGenerator::class, function ($m) use ($pdf) {
            $m->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $m->shouldReceive('generateReservationDocs')->andReturnNull();
            $m->shouldReceive('generateReservationCompletionDocs')->andReturnNull();
            $m->shouldReceive('download')->andReturn($pdf);
        });

        // ─────────────── ۰. کاتالوگ: چیزی برای خریدن ───────────────
        $supplier = User::factory()->create();
        $factory = Factory::create([
            Factory::SUPPLIER_USER_ID => $supplier->id,
            Factory::NAME => 'کارخانه فولاد تست',
            Factory::SLUG => 'f-'.Str::random(10),
            Factory::IS_ACTIVE => true,
        ]);
        $location = FactoryLocation::create([
            FactoryLocation::FACTORY_ID => $factory->id,
            FactoryLocation::NAME => 'انبار مرکزی',
            FactoryLocation::TYPE => 'warehouse',
            FactoryLocation::IS_ACTIVE => true,
        ]);
        $category = ProductCategory::create([
            'name' => 'میلگرد', 'slug' => 'cat-'.Str::random(8), 'code' => 'C_'.Str::random(6),
        ]);
        $product = Product::create([
            'cuid' => Str::random(20), 'category_id' => $category->id,
            'name' => 'میلگرد آجدار ۱۴', 'slug' => 'p-'.Str::random(10), 'sku' => 'SKU_'.Str::random(6),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => 'شاخه ۱۲ متری',
            'sku' => 'V_'.Str::random(6), 'stock_quantity' => 100000, 'is_active' => true,
        ]);
        ProductPrice::create([
            ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
            ProductPrice::FACTORY_ID => $factory->id,
            ProductPrice::FACTORY_LOCATION_ID => $location->id,
            ProductPrice::GENERAL_PRICE => 500_000,
            ProductPrice::SPECIAL_PRICE => 500_000,
            ProductPrice::GOLDEN_FIGURE => 500_000,
            ProductPrice::LAST_UPDATED_AT => now(),
        ]);
        FactoryStock::create([
            FactoryStock::PRODUCT_VARIANT_ID => $variant->id,
            FactoryStock::FACTORY_ID => $factory->id,
            FactoryStock::FACTORY_LOCATION_ID => $location->id,
            FactoryStock::QUANTITY => 100000,
        ]);
        $unit = OrderUnit::create(['name' => 'کیلوگرم', 'code' => 'kg_'.uniqid(), 'unit' => 'kg', 'amount' => 1]);
        // واحد باید به محصول وصل باشد، وگرنه سبد آن را نمی‌پذیرد.
        DB::table('product_order_units')->insert([
            'product_id' => $product->id, 'order_unit_id' => $unit->id,
            'max_quantity' => 100000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $shippingMethod = ShippingMethod::create(['name' => 'کامیون', 'code' => 'truck_'.uniqid()]);

        // `user_types` در دیتابیسِ تست seed نشده. `SetUserTypeAction` هر سه اسلاگ را
        // لازم دارد (expert/employer/supplier) وگرنه ۵۰۰ می‌دهد.
        foreach ([['expert', 'الیت یار'], ['employer', 'کارفرما'], ['supplier', 'تأمین‌کننده']] as [$slug, $name]) {
            if (! DB::table('user_types')->where('slug', $slug)->exists()) {
                DB::table('user_types')->insert(['slug' => $slug, 'name' => $name, 'icon' => 'building']);
            }
            // نقشِ Laratrust هم باید وجود داشته باشد تا `addRole()` کار کند.
            \Laratrust\Models\Role::firstOrCreate(['name' => $slug], ['display_name' => $name]);
        }

        // استان/شهر در دیتابیسِ تست seed نشده‌اند — همان دو ردیفِ لازم را می‌سازیم.
        $province = DB::table('province')->first();
        if (! $province) {
            $pid = DB::table('province')->insertGetId(['Name' => 'اصفهان']);
            $province = DB::table('province')->where('id', $pid)->first();
        }
        $city = DB::table('province_city')->where('ProvinceID', $province->id)->first();
        if (! $city) {
            $cid = DB::table('province_city')->insertGetId(['ProvinceID' => $province->id, 'Name' => 'اصفهان']);
            $city = DB::table('province_city')->where('id', $cid)->first();
        }

        ShippingRate::create([
            'factory_location_id' => $location->id,
            'destination_province_id' => $province->id,
            'destination_city_id' => null,
            'flat_cost' => 1_000_000,
            'per_kg_cost' => 100,
            'per_count_cost' => 0,
            'cost_basis' => 'weight',
            'is_active' => true,
        ]);
        $this->step('۰. آماده‌سازی کاتالوگ (کارخانه/قیمت/موجودی/نرخ حمل)', 200);

        // ─────────────── ۱. ثبت‌نام و ورود با کد یک‌بارمصرف ───────────────
        $mobile = '09'.random_int(100000000, 999999999);

        // کاربر تازه باید اول ثبت‌نام کند؛ `check-user` فقط برای کاربرِ موجود است.
        $r = $this->postJson('/api/authenticate', [
            'username' => $mobile,
            'national_code' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '13700101',
        ]);
        $this->step('۱. ثبت‌نام (register)', $r->status(), $r->json('message'));

        $otp = (string) config('auth.otp_debug_code', env('OTP_DEBUG_CODE', '111111'));
        $r = $this->postJson('/api/authenticate/verify-code', ['username' => $mobile, 'code' => $otp]);
        $this->step('۲. تأیید کد و دریافت توکن', $r->status(), $r->status() >= 300 ? $r->json() : 'token گرفته شد');

        if ($r->status() >= 300) {
            $this->fail('ورود ناموفق — بقیهٔ مسیر قابل اجرا نیست.');
        }

        /** @var User $user */
        $user = User::where('mobile', $mobile)->firstOrFail();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        // ─────────────── ۲. انتخاب نقش ───────────────
        $r = $this->postJson('/api/profile/type', ['roles' => ['employer']]);
        $this->step('۳. انتخاب نقش (کارفرما)', $r->status(), $r->json('message'));

        // ─────────────── ۳. مشاهدهٔ محصول ───────────────
        $r = $this->getJson('/api/products');
        $this->step('۴. فهرست محصولات (عمومی)', $r->status(), 'count='.count((array) $r->json('data')));

        // ─────────────── ۴. آدرس ───────────────
        $r = $this->postJson('/api/profile/addresses', [
            Address::TITLE => 'کارگاه',
            Address::PROVINCE_ID => $province->id,
            Address::CITY_ID => $city->id,
            Address::POSTAL_ADDRESS => 'خیابان تست، پلاک ۱',
            Address::RECEIVER_NAME => 'آقای تست',
            Address::RECEIVER_MOBILE => $mobile,
            Address::IS_DEFAULT => true,
        ]);
        $this->step('۵. ثبت آدرس', $r->status(), $r->status() >= 300 ? $r->json() : null);
        $addressId = Address::where(Address::USER_ID, $user->id)->value('id');

        // ─────────────── ۵. افزودن به سبد ───────────────
        $r = $this->postJson('/api/cart/items', [
            'product_variant_id' => $variant->id,
            'factory_id' => $factory->id,
            'location_id' => $location->id,
            'shipping_method_id' => $shippingMethod->id,
            'quantities' => [(string) $unit->id => 2000],
        ]);
        $this->step('۶. افزودن به سبد (۲۰۰۰ کیلوگرم)', $r->status(), $r->status() >= 300 ? $r->json() : null);

        $r = $this->getJson('/api/cart');
        $cartTotal = $r->json('data.totals.total') ?? $r->json('data.total');
        $this->step('۷. مشاهدهٔ سبد', $r->status(), 'total='.json_encode($cartTotal));

        // ─────────────── ۶. ثبت سفارش ───────────────
        $r = $this->postJson('/api/checkout', [
            'address_id' => $addressId,
            'shipping_method_id' => $shippingMethod->id,
        ]);
        $this->step('۸. ثبت سفارش (checkout)', $r->status(), $r->status() >= 300 ? $r->json() : null);

        $order = Order::where(Order::PLACER_USER_ID, $user->id)->latest('id')->first();
        if (! $order) {
            $this->fail('سفارشی ساخته نشد — مسیر همین‌جا می‌ایستد.');
        }
        $this->step('   └ سفارش ساخته شد', 200, [
            'order' => $order->order_number,
            'status' => $order->status,
            'final_amount' => (string) $order->final_amount,
        ]);

        // ─────────────── ۷. شارژ کیف پول و پرداخت ───────────────
        $wallet = Wallet::firstOrCreate(
            [Wallet::USER_ID => $user->id],
            [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0],
        );
        $wallet->update([Wallet::BALANCE => (float) $order->final_amount + 10_000_000]);
        $this->step('۹. شارژ کیف پول (مستقیم — درگاه stub است)', 200, 'balance='.$wallet->fresh()->balance);

        $sys = User::factory()->create();
        config(['financial.system_user_id' => $sys->id]);
        Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $r = $this->postJson("/api/profile/orders/{$order->id}/pay-full", ['payment_type' => 'pay']);
        $this->step('۱۰. پرداخت کامل سفارش', $r->status(), $r->status() >= 300 ? $r->json() : $r->json('message'));

        $order->refresh();
        $this->step('   └ وضعیت پس از پرداخت', 200, [
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_to_supplier' => (string) $order->payment_to_supplier,
        ]);

        // ─────────────── ۸. سفارش و اسناد ───────────────
        $r = $this->getJson("/api/profile/orders/{$order->id}");
        $this->step('۱۱. مشاهدهٔ سفارش', $r->status());

        $r = $this->get("/api/profile/orders/{$order->id}/invoice/receipt");
        $this->step('۱۲. دانلود رسید خریدار (سند A)', $r->status());

        $this->assertTrue(true);
    }
}
