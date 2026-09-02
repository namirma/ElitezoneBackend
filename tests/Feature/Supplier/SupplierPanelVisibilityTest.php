<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierActivityCategory;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تأمین‌کننده باید بتواند چیزی را که کسب‌وکارش را اداره می‌کند **ببیند**.
 *
 * چهار مورد که هر کدام یک‌طرفه بودند:
 *
 *   ۱. `reserve_time` نوشته می‌شد و در هیچ پاسخی برنمی‌گشت — با اینکه انقضایش
 *      کلِ کالای تأمین‌کننده را از کاتالوگ حذف می‌کند.
 *   ۲. `supplier_vat_pending_amount` در هیچ پاسخی نبود، پس مبلغی که باید شارژ
 *      کند تا عودتِ خریدار آزاد شود قابل محاسبه نبود.
 *   ۳. متقاضیِ ردشده هرگز نمی‌توانست دوباره درخواست بدهد.
 *   ۴. `activityCategory-getId` کلِ جدولِ واسطِ همه‌ی تأمین‌کننده‌ها را به هر
 *      کاربرِ احرازشده‌ای می‌داد.
 */
class SupplierPanelVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
    }

    private function profileFor(User $user, array $attrs = []): SupplierProfile
    {
        $profile = SupplierProfile::create([SupplierProfile::USER_ID => $user->id]);

        if ($attrs !== []) {
            $profile->forceFill($attrs)->save();
        }

        return $profile->fresh();
    }

    #[Test]
    public function the_price_window_the_supplier_sets_is_readable_back(): void
    {
        $this->profileFor($this->supplier, [
            SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
            SupplierProfile::RESERVE_TIME => now()->addDays(3),
            SupplierProfile::RESERVE_AUTO_EXTEND_MINUTES => 120,
            SupplierProfile::RESERVE_GRACE_HOURS => 5,
        ]);

        Sanctum::actingAs($this->supplier);
        $data = $this->getJson('/api/profile/supplier')->assertOk()->json('data');

        $this->assertNotNull($data[SupplierProfile::RESERVE_TIME] ?? null, 'پنجره‌ی قیمت باید برگردد.');
        $this->assertSame(120, (int) $data[SupplierProfile::RESERVE_AUTO_EXTEND_MINUTES]);
        $this->assertSame(5, (int) $data[SupplierProfile::RESERVE_GRACE_HOURS]);
        $this->assertTrue($data['price_window_valid'], 'پنجره‌ی آینده باید معتبر گزارش شود.');

        // و پرچم‌های فرمول که تا امروز فقط از داشبورد دیده می‌شدند.
        $this->assertArrayHasKey(SupplierProfile::TRANS_COMMISSION, $data);
        $this->assertArrayHasKey(SupplierProfile::TRANS_VAT, $data);
    }

    #[Test]
    public function an_expired_price_window_is_reported_as_invalid(): void
    {
        // این همان حالتی است که کالای تأمین‌کننده را نامرئی می‌کند؛ باید بتواند
        // علتش را از پنل بفهمد، نه از نیامدنِ سفارش.
        $this->profileFor($this->supplier, [
            SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
            SupplierProfile::RESERVE_TIME => now()->subDay(),
        ]);

        Sanctum::actingAs($this->supplier);

        $this->assertFalse(
            $this->getJson('/api/profile/supplier')->assertOk()->json('data.price_window_valid')
        );
    }

    #[Test]
    public function the_supplier_is_told_how_much_to_top_up(): void
    {
        // بلوکِ `supplier_settlement` روی برنچِ L13 وجود ندارد و پیش‌نیازش هم
        // آنجا نیست: `$isSupplier` هنوز نقشِ **سراسری** است نه «تأمین‌کننده‌ی
        // همین سفارش». افزودنِ ارقامِ تسویه پشتِ آن پرچم یعنی نمایششان روی
        // سفارشی که بیننده فقط خریدارش است — همان نشتی که `partyRolesFor()`
        // برای بستنش آمد. پس پورتِ آن بلوک به L13 تغییرِ جداگانه‌ای است و تا آن
        // زمان اینجا skip می‌شود، نه اینکه فایلِ تست بین دو برنچ فورک شود.
        if (! method_exists(Order::class, 'partyRolesFor')) {
            $this->markTestSkipped('supplier_settlement is not on this branch.');
        }

        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'TOPUP-'.uniqid(),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        $order->forceFill([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => 3_000_000,
            Order::SUPPLIER_VAT_PENDING_AMOUNT => 700_000,
            Order::WEIGHING_COMPLETED_AT => now(),
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            'supplier_user_id' => $this->supplier->id,
        ]);

        Sanctum::actingAs($this->supplier);
        $settlement = $this->getJson("/api/profile/orders/{$order->id}")
            ->assertOk()
            ->json('data.financial.supplier_settlement');

        $this->assertSame('700000', $settlement[Order::SUPPLIER_VAT_PENDING_AMOUNT]);

        // `CheckSupplierBalancesJob` تنها وقتی آزاد می‌کند که موجودی از مجموعِ
        // این دو بیشتر باشد — پس همان مجموع عددی است که به کار می‌آید.
        $this->assertSame('3700000', $settlement['required_top_up']);
    }

    #[Test]
    public function a_rejected_applicant_can_re_apply_by_editing_their_profile(): void
    {
        $applicant = User::factory()->create();
        $this->profileFor($applicant, [SupplierProfile::STATUS => SupplierProfile::STATUS_REJECTED]);
        $category = SupplierActivityCategory::create(['name' => 'میلگرد '.uniqid()]);

        Sanctum::actingAs($applicant);
        $this->postJson('/api/profile/supplier', [
            'company' => 'شرکت اصلاح‌شده',
            'activity_category_id' => [$category->id],
        ])->assertOk();

        $this->assertSame(
            SupplierProfile::STATUS_PENDING,
            SupplierProfile::where(SupplierProfile::USER_ID, $applicant->id)->value(SupplierProfile::STATUS),
            'ویرایشِ پروفایل پس از رد یعنی درخواستِ دوباره — باید به صف برگردد.'
        );
    }

    #[Test]
    public function editing_an_approved_profile_does_not_drop_the_approval(): void
    {
        $this->profileFor($this->supplier, [SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED]);
        $category = SupplierActivityCategory::create(['name' => 'ورق '.uniqid()]);

        Sanctum::actingAs($this->supplier);
        $this->postJson('/api/profile/supplier', [
            'company' => 'نامِ تازه',
            'activity_category_id' => [$category->id],
        ])->assertOk();

        $this->assertSame(
            SupplierProfile::STATUS_APPROVED,
            SupplierProfile::where(SupplierProfile::USER_ID, $this->supplier->id)->value(SupplierProfile::STATUS)
        );
    }

    #[Test]
    public function the_activity_category_endpoint_no_longer_leaks_every_supplier(): void
    {
        $mine = $this->profileFor($this->supplier);
        $theirs = $this->profileFor(User::factory()->create());

        $a = SupplierActivityCategory::create(['name' => 'مالِ من '.uniqid()]);
        $b = SupplierActivityCategory::create(['name' => 'مالِ دیگری '.uniqid()]);

        $mine->activities()->sync([$a->id]);
        $theirs->activities()->sync([$b->id]);

        Sanctum::actingAs($this->supplier);
        $ids = $this->getJson('/api/profile/supplier/activityCategory-getId')
            ->assertOk()
            ->json('data');

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids, 'دسته‌ی تأمین‌کننده‌ی دیگر نباید دیده شود.');
    }

    #[Test]
    public function a_user_with_no_supplier_profile_gets_an_empty_list_not_everyone_elses(): void
    {
        $this->profileFor(User::factory()->create())
            ->activities()->sync([SupplierActivityCategory::create(['name' => 'x'.uniqid()])->id]);

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->assertSame(
            [],
            $this->getJson('/api/profile/supplier/activityCategory-getId')->assertOk()->json('data')
        );

        $this->assertGreaterThan(0, DB::table('supplier_activities')->count(), 'فیکسچر باید ردیف داشته باشد.');
    }
}
