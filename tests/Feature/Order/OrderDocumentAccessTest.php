<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Invoice\InvoiceGenerator;
use App\Support\OrderDocumentCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دانلودِ اسنادِ سفارش — یک تستِ جارویی روی **کلِ** کاتالوگ، نه چند مورد نمونه.
 *
 * انگیزه‌اش یک نقصِ واقعی است: دانشِ «کدام سند مالِ کیست» در دو جا کپی شده بود —
 * نقشهٔ مخاطبان در کنترلر، و یک whitelist دستی روی روت — و آن دو واگرا شدند تا
 * جایی که اشتراکشان برای تأمین‌کننده و الیت‌یار تهی شد:
 *
 *     supplier_report → ۴۰۴    bill_of_lading → ۴۰۴    tax_supplier → ۴۰۴
 *     initial → ۴۰۳            final → ۴۰۳             tax → ۴۰۳
 *
 * یعنی تأمین‌کننده **هیچ** سندی نمی‌توانست بگیرد؛ از جمله Doc K (بارنامه / مجوز
 * خروج) و Doc C (دستور بارگیری) که بدونشان بار از کارخانه خارج نمی‌شود، و Doc O
 * که خودش تازه ثبتش کرده بود.
 *
 * چون تستِ نمونه‌ای این را نمی‌گرفت (هر سه اسلاگی که تست می‌شدند مالِ خریدار
 * بودند و کار می‌کردند)، این تست روی هر اسلاگِ کاتالوگ × هر سه مخاطب حرکت می‌کند.
 * افزودنِ سندِ تازه یا تنگ‌کردنِ دوبارهٔ whitelist روت، همین‌جا قرمز می‌شود.
 */
class OrderDocumentAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $buyer;

    private User $supplier;

    private User $eliteYar;

    private User $stranger;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // تولیدِ PDF عمداً mock می‌شود: فونت‌های فارسی gitignore شده‌اند و روی یک
        // کلونِ تازه وجود ندارند، پس تستِ *مجوز* نباید به رندرِ mPDF گره بخورد.
        $pdf = Mockery::mock();
        $pdf->shouldReceive('download')->andReturn(response('%PDF-stub'));

        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('download')
            ->andReturn($pdf);

        $this->buyer = User::factory()->create();
        $this->eliteYar = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ELITE_YAR_USER_ID => $this->eliteYar->id,
            Order::ORDER_NUMBER => 'DOC-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::FINAL_AMOUNT => 1_000_000,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
        ]);
    }

    private function url(string $slug): string
    {
        return "/api/profile/orders/{$this->order->id}/invoice/{$slug}";
    }

    #[Test]
    public function every_document_reaches_exactly_its_own_audience(): void
    {
        $parties = [
            OrderDocumentCatalog::AUDIENCE_BUYER => $this->buyer,
            OrderDocumentCatalog::AUDIENCE_SUPPLIER => $this->supplier,
            OrderDocumentCatalog::AUDIENCE_ELITE_YAR => $this->eliteYar,
        ];

        $failures = [];

        foreach (OrderDocumentCatalog::downloadableSlugs() as $slug) {
            $allowed = OrderDocumentCatalog::audiencesFor($slug);

            $this->assertNotEmpty(
                $allowed,
                "سند «{$slug}» کد دارد ولی هیچ مخاطبی برایش تعریف نشده — برای همه ممنوع می‌شود."
            );

            foreach ($parties as $audience => $user) {
                Sanctum::actingAs($user);

                $expected = in_array($audience, $allowed, true) ? 200 : 403;
                $actual = $this->get($this->url($slug))->getStatusCode();

                if ($actual !== $expected) {
                    $failures[] = "{$slug} / {$audience}: انتظار {$expected}، دریافت {$actual}";
                }
            }
        }

        $this->assertSame([], $failures, "دسترسی به اسناد با نقشهٔ مخاطبان نمی‌خواند:\n".implode("\n", $failures));
    }

    #[Test]
    public function a_supplier_can_download_the_documents_that_used_to_be_unreachable(): void
    {
        // همان شش موردی که در گزارش ۴۰۴ می‌دادند — بارنامه و دستور بارگیری
        // بحرانی‌ترین‌اند: خروجِ فیزیکیِ بار به آن‌ها وابسته است.
        Sanctum::actingAs($this->supplier);

        foreach (['bill_of_lading', 'loading', 'supplier_report', 'final_supplier', 'modified_supplier', 'tax_supplier'] as $slug) {
            $this->get($this->url($slug))->assertOk();
        }
    }

    #[Test]
    public function a_supplier_still_cannot_read_the_buyers_documents(): void
    {
        // نقشهٔ مخاطبان برای بستنِ همین نشت نوشته شده بود؛ باز کردنِ روت نباید
        // آن را هم باز کرده باشد.
        Sanctum::actingAs($this->supplier);

        foreach (['initial', 'receipt', 'final', 'tax', 'modified_owner', 'refund'] as $slug) {
            $this->get($this->url($slug))->assertForbidden();
        }
    }

    #[Test]
    public function someone_who_is_not_a_party_to_the_order_is_stopped_before_the_audience_check(): void
    {
        Sanctum::actingAs($this->stranger);

        // گاردِ عمومیِ OrderPolicy::view — پیش از نقشهٔ مخاطبان.
        $this->get($this->url('initial'))->assertForbidden();
        $this->get($this->url('supplier_report'))->assertForbidden();
    }

    #[Test]
    public function the_survey_documents_stay_out_of_the_route(): void
    {
        // Doc S / Doc T در محصولِ فعلی وجود ندارند (همان تصمیمی که
        // features.order_surveys را خاموش کرد). کد و Blade نگه داشته شده‌اند ولی
        // نباید از این روت دانلود شوند.
        Sanctum::actingAs($this->buyer);

        $this->get($this->url('survey_buyer'))->assertNotFound();
        $this->get($this->url('survey_elite_yar'))->assertNotFound();
    }

    #[Test]
    public function an_admin_may_download_any_partys_document(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        Sanctum::actingAs($admin);

        $this->get($this->url('initial'))->assertOk();
        $this->get($this->url('tax_supplier'))->assertOk();
        $this->get($this->url('tax_elite_yar'))->assertOk();
    }

    #[Test]
    public function the_route_whitelist_is_derived_from_the_catalog_not_hand_written(): void
    {
        // این تست دقیقاً واگراییِ اصلی را می‌گیرد: whitelist روت باید برابرِ کلِ
        // کاتالوگ منهای اسنادِ خارج از دامنهٔ محصول باشد. اگر کسی دوباره فهرستی
        // دستی بنویسد، شمارش‌ها از هم می‌پاشند.
        $slugs = OrderDocumentCatalog::downloadableSlugs();

        $this->assertContains('bill_of_lading', $slugs);
        $this->assertContains('tax_supplier', $slugs);
        $this->assertContains('elite_yar_report', $slugs);
        $this->assertNotContains('survey_buyer', $slugs);
        $this->assertNotContains('survey_elite_yar', $slugs);

        foreach ($slugs as $slug) {
            $this->assertNotNull(
                OrderDocumentCatalog::codeFor($slug),
                "اسلاگ «{$slug}» در whitelist روت هست ولی کدِ سندی ندارد."
            );
        }
    }
}
