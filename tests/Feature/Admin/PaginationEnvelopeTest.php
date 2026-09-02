<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * هر فهرستِ صفحه‌بندی‌شده باید بگوید چند صفحه دارد.
 *
 * ‏`success(XResource::collection($paginator))` — الگوی ۴۹ اندپوینتِ فهرست در
 * پنل ادمین و پنل تأمین‌کننده — `links`/`meta` را دور می‌ریخت: وقتی یک
 * `ResourceCollection` تودرتو سریالایز شود، لاراول فقط `toArray()` را صدا
 * می‌زند و متدی که متادیتا را می‌سازد (`toResponse()`) هرگز اجرا نمی‌شود.
 *
 * پس کوئریِ `COUNT` اجرا می‌شد، `LIMIT/OFFSET` اعمال می‌شد، و بعد نتیجه‌اش دور
 * ریخته می‌شد. کلاینت صفحه‌ی اول را می‌گرفت و هیچ راهی نداشت بفهمد صفحه‌ی دومی
 * هست یا نه — یعنی **هیچ فهرستی در هیچ‌کدام از دو پنل قابل ورق‌زدن نبود**، و
 * چون پاسخ ۲۰۰ و ظاهراً سالم بود، هیچ‌جا خطایی دیده نمی‌شد.
 */
class PaginationEnvelopeTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    /**
     * نمونه‌ای از هر دو پنل. عمداً اندپوینت‌هایی انتخاب شده‌اند که بدونِ داده‌ی
     * پیش‌ساخته هم ۲۰۰ می‌دهند — موضوعِ سنجش پاکتِ پاسخ است، نه محتوایش.
     *
     * @var list<string>
     */
    private const PAGINATED = [
        '/api/admin/users',
        '/api/admin/orders',
        '/api/admin/coupons',
        '/api/admin/tickets',
        '/api/admin/suppliers',
        '/api/admin/financial/company/invoices',
    ];

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);

        return $admin;
    }

    #[Test]
    public function every_paginated_admin_list_reports_its_page_count(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (self::PAGINATED as $path) {
            $body = $this->getJson($path)->assertOk()->json();

            $this->assertArrayHasKey('meta', $body, "{$path} متادیتای صفحه‌بندی ندارد.");
            $this->assertArrayHasKey('total', $body['meta'], "{$path} شمارشِ کل را برنمی‌گرداند.");
            $this->assertArrayHasKey('last_page', $body['meta'], "{$path} تعداد صفحات را برنمی‌گرداند.");
            $this->assertArrayHasKey('links', $body, "{$path} لینک‌های صفحه‌بندی ندارد.");

            // و `data` باید همان آرایه‌ی مستقیم بماند — کلاینت‌های فعلی روی
            // همین نوشته شده‌اند و این تغییر عمداً شکستنی نیست.
            $this->assertIsArray($body['data'], "{$path}: data باید آرایه بماند.");
        }
    }

    #[Test]
    public function a_non_paginated_list_gets_no_envelope(): void
    {
        // ‏`GET /api/province` مجموعه‌ی ساده برمی‌گرداند (بدون paginate) و روی هر
        // دو برنچ هست. نباید `meta` بگیرد، وگرنه شکلِ پاسخ بی‌دلیل عوض می‌شود.
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/province')->assertOk()->json();

        $this->assertArrayNotHasKey('meta', $body);
        $this->assertArrayNotHasKey('links', $body);
    }

    #[Test]
    public function the_supplier_panel_lists_page_too(): void
    {
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));

        Sanctum::actingAs($supplier);

        foreach (['/api/profile/supplier/factories', '/api/profile/supplier/orders'] as $path) {
            $body = $this->getJson($path)->assertOk()->json();

            $this->assertArrayHasKey('meta', $body, "{$path} متادیتای صفحه‌بندی ندارد.");
            $this->assertIsArray($body['data']);
        }
    }
}
