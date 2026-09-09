<?php

declare(strict_types=1);

namespace Tests\Feature\Supplier;

use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SupplierRequest;
use App\Models\User;
use App\Services\Sales\SalesSettings;
use App\Services\Supplier\PriceChangeApprovalGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دروازه‌ی «تغییرِ قیمتِ بزرگ تأییدِ ادمین می‌خواهد» (تصمیم مدیریت، پرسش ۱).
 *
 * تمرکزِ این تست روی خودِ دروازه است، چون همان تکه‌ای است که هر دو مسیرِ نوشتن
 * از آن رد می‌شوند — و اگر بشکند، هر دو بی‌صدا باز می‌شوند.
 */
class PriceChangeApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private function gate(float $threshold = 15.0): PriceChangeApprovalGate
    {
        // `Setting::booted()` خودش کش را روی `saved` پاک می‌کند، پس پاک‌کردنِ
        // دستی لازم نیست — و متدی هم برایش وجود ندارد.
        Setting::query()->updateOrCreate(
            [Setting::KEY => 'price_change_approval_percent'],
            [Setting::VALUE => (string) $threshold],
        );

        return new PriceChangeApprovalGate(new SalesSettings);
    }

    private function price(float $general): ProductPrice
    {
        $p = new ProductPrice;
        $p->{ProductPrice::GENERAL_PRICE} = $general;
        $p->{ProductPrice::SPECIAL_PRICE} = 0;
        $p->{ProductPrice::GOLDEN_FIGURE} = 0;

        return $p;
    }

    #[Test]
    public function a_small_change_is_applied_immediately(): void
    {
        $queued = $this->gate()->evaluate(
            $this->price(100_000),
            [ProductPrice::GENERAL_PRICE => 110_000],   // ۱۰٪
        );

        $this->assertNull($queued, 'تغییرِ زیرِ آستانه نباید به صف برود.');
    }

    #[Test]
    public function a_change_above_the_threshold_is_queued(): void
    {
        $queued = $this->gate()->evaluate(
            $this->price(100_000),
            [ProductPrice::GENERAL_PRICE => 130_000],   // ۳۰٪
        );

        $this->assertNotNull($queued);
        $this->assertSame(30.0, $queued['change_percent']);
        $this->assertSame('100000', $queued['current_general_price']);
        $this->assertSame('130000', $queued['proposed_general_price']);
    }

    /**
     * ⚠️ کاهشِ بزرگ هم باید تأیید بخواهد.
     *
     * وسوسه‌ی طبیعی این است که فقط گران‌شدن را بگیریم. ولی قیمتِ اشتباهِ **پایین**
     * یعنی فروش زیرِ قیمت، که ضررش از گران‌فروشی کمتر نیست و خودش را هم نشان
     * نمی‌دهد (کسی شکایت نمی‌کند که ارزان خریده).
     */
    #[Test]
    public function a_large_price_drop_is_queued_too(): void
    {
        $queued = $this->gate()->evaluate(
            $this->price(100_000),
            [ProductPrice::GENERAL_PRICE => 20_000],    // ۸۰٪ کاهش
        );

        $this->assertNotNull($queued, 'کاهشِ بزرگ هم باید تأیید بخواهد.');
        $this->assertSame(80.0, $queued['change_percent']);
    }

    /** رفتنِ از قیمتِ صفر به یک قیمتِ واقعی «تغییرِ بی‌نهایت» است، نه صفر. */
    #[Test]
    public function pricing_a_row_that_had_no_price_is_queued(): void
    {
        $queued = $this->gate()->evaluate(
            $this->price(0),
            [ProductPrice::GENERAL_PRICE => 50_000],
        );

        $this->assertNotNull($queued, 'صفر → قیمت نباید بی‌صدا از دروازه رد شود.');
    }

    #[Test]
    public function an_unchanged_price_is_never_queued(): void
    {
        $queued = $this->gate()->evaluate(
            $this->price(100_000),
            [ProductPrice::GENERAL_PRICE => 100_000],
        );

        $this->assertNull($queued);
    }

    /** آستانه از `settings` خوانده می‌شود، نه هاردکد. */
    #[Test]
    public function the_threshold_is_configurable(): void
    {
        $change = [ProductPrice::GENERAL_PRICE => 120_000];   // ۲۰٪

        $this->assertNull(
            $this->gate(25.0)->evaluate($this->price(100_000), $change),
            'با آستانه‌ی ۲۵٪ یک تغییرِ ۲۰٪ باید فوراً اعمال شود.',
        );

        $this->assertNotNull(
            $this->gate(10.0)->evaluate($this->price(100_000), $change),
            'با آستانه‌ی ۱۰٪ همان تغییر باید به صف برود.',
        );
    }

    /** صفر یعنی «همه‌چیز تأیید می‌خواهد» — سمتِ محافظه‌کارانه. */
    #[Test]
    public function a_zero_threshold_queues_every_change(): void
    {
        $queued = $this->gate(0.0)->evaluate(
            $this->price(100_000),
            [ProductPrice::GENERAL_PRICE => 100_100],   // ۰٫۱٪
        );

        $this->assertNotNull($queued);
    }

    #[Test]
    public function queueing_writes_a_pending_request_of_the_new_type(): void
    {
        $supplier = User::factory()->create();

        $request = $this->gate()->queue($supplier->id, [
            'product_price_id' => 1,
            'change_percent' => 30.0,
        ], 'دلیلِ آزمون');

        $this->assertSame(SupplierRequest::TYPE_PRICE_CHANGE, $request->{SupplierRequest::TYPE});
        $this->assertTrue($request->refresh()->isPending());
        $this->assertContains(
            SupplierRequest::TYPE_PRICE_CHANGE,
            SupplierRequest::TYPES,
            'نوعِ تازه باید در TYPES باشد وگرنه فیلترِ اندپوینتِ ادمین ۴۲۲ می‌دهد.',
        );
    }

    /** مهلت باید ست شود، وگرنه کرونِ انقضا هیچ‌وقت این ردیف را نمی‌بیند. */
    #[Test]
    public function queueing_stamps_the_review_deadline(): void
    {
        $supplier = User::factory()->create();

        $request = $this->gate()->queue($supplier->id, ['product_price_id' => 1], 'دلیلِ آزمون');

        $this->assertNotNull($request->{SupplierRequest::REVIEW_DEADLINE});
        $this->assertEqualsWithDelta(
            48,
            now()->diffInHours($request->{SupplierRequest::REVIEW_DEADLINE}),
            1,
            'مهلتِ پیش‌فرض طبق تصمیم مدیریت ۴۸ ساعت است.',
        );
    }

    /**
     * ⚠️ منقضی‌شدن = **رد**، نه پذیرش.
     *
     * مدیریت مهلت را تعیین کرد ولی نگفت پس از آن چه شود. انتخابِ ما سمتِ
     * محافظه‌کارانه است: قیمتی که هیچ ادمینی ندید نباید با سکوت روی سایت بنشیند،
     * و رد برگشت‌پذیر است (تأمین‌کننده دوباره ثبت می‌کند) در حالی که پذیرشِ
     * خودکار نیست — قیمت رفته و مشتری دیده است.
     */
    #[Test]
    public function an_unreviewed_request_is_rejected_when_the_deadline_passes(): void
    {
        $supplier = User::factory()->create();
        $request = $this->gate()->queue($supplier->id, ['product_price_id' => 1], 'دلیلِ آزمون');

        $request->forceFill([SupplierRequest::REVIEW_DEADLINE => now()->subHour()])->save();

        $this->artisan('supplier-requests:expire-price-changes')->assertSuccessful();

        $request->refresh();
        $this->assertSame(SupplierRequest::STATUS_REJECTED, $request->{SupplierRequest::STATUS});
        $this->assertNull(
            $request->{SupplierRequest::REVIEWED_BY},
            'هیچ ادمینی این تصمیم را نگرفت — پرکردنِ این ستون سابقه را دروغ می‌کند.',
        );
    }

    /** ردیفی که مهلتش نگذشته دست‌نخورده می‌ماند. */
    #[Test]
    public function a_request_inside_its_deadline_is_left_alone(): void
    {
        $supplier = User::factory()->create();
        $request = $this->gate()->queue($supplier->id, ['product_price_id' => 1], 'دلیلِ آزمون');

        $this->artisan('supplier-requests:expire-price-changes')->assertSuccessful();

        $this->assertTrue($request->refresh()->isPending());
    }

    /** دو نوعِ دیگر مهلت ندارند و کرون نباید لمسشان کند. */
    #[Test]
    public function the_expiry_cron_never_touches_the_other_request_types(): void
    {
        $supplier = User::factory()->create();
        $other = SupplierRequest::create([
            SupplierRequest::SUPPLIER_USER_ID => $supplier->id,
            SupplierRequest::TYPE => SupplierRequest::TYPE_CATALOG_ADDITION,
            SupplierRequest::REASON => 'دلیلِ آزمون',
        ]);

        $this->artisan('supplier-requests:expire-price-changes')->assertSuccessful();

        $this->assertTrue($other->refresh()->isPending());
    }
}
