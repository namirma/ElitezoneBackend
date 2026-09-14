<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * پاکتی که `api.json` اعلام می‌کند باید همان پاسخِ واقعی باشد.
 *
 * شکلِ پاسخِ `success()`/`created()` را `SuccessResponseExtension` برای Scramble
 * **دستی** اعلام می‌کند، چون Scramble از ماکروی `Response::success` عبور نمی‌کند.
 * یعنی دو منبعِ حقیقت‌اند: ماکرو (`ResponseServiceProvider`) و افزونه. گیتِ CI فقط
 * می‌گوید `api.json` با خروجیِ Scramble یکی است، نه اینکه خروجیِ Scramble درست است
 * — اگر روزی `paginationEnvelope` کلیدی عوض کند، سند بی‌صدا دروغ می‌گفت.
 *
 * هر سه شکلِ ماکرو یک نمونه دارند: شیءِ ساده، کالکشنِ صفحه‌بندی‌شده (`meta`/`links`
 * کنارِ `data`)، و صفحه‌بندِ خام (`links` با کلیدهای `*_page_url`).
 */
class SuccessEnvelopeIsDocumentedTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    #[Test]
    public function a_plain_success_response_has_exactly_the_documented_keys(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertMatchesDocumentation('/profile/debts', $this->getJson('/api/profile/debts')->assertOk()->json());
    }

    #[Test]
    public function a_paginated_resource_list_documents_meta_and_links_beside_data(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $this->assertMatchesDocumentation('/admin/coupons', $this->getJson('/api/admin/coupons')->assertOk()->json());
    }

    #[Test]
    public function a_raw_paginator_documents_its_page_url_links(): void
    {
        $supplier = User::factory()->create();
        $supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'Supplier']));
        Sanctum::actingAs($supplier);

        $this->assertMatchesDocumentation('/profile/supplier/requests', $this->getJson('/api/profile/supplier/requests')->assertOk()->json());
    }

    #[Test]
    public function an_error_response_has_exactly_the_documented_keys(): void
    {
        // ‏`error()` هم از ماکرو می‌گذرد؛ بدونِ اعلامش، متدی که یک شاخه `success`
        // و شاخه‌ی دیگر `error` برمی‌گرداند هر دو پاسخش را بی‌شکل می‌کرد.
        $body = $this->getJson('/api/agreements/no-such-agreement')->assertNotFound()->json();

        $this->assertMatchesDocumentation('/agreements/{slug}', $body, 404);
    }

    /** @param  array<string, mixed>  $body */
    private function assertMatchesDocumentation(string $path, array $body, int $status = 200): void
    {
        $schema = $this->documentedSchema($path, $status);

        $this->assertEqualsCanonicalizing(
            array_keys($schema['properties'] ?? []),
            array_keys($body),
            "{$path}: کلیدهای سطحِ اولِ پاسخ با api.json نمی‌خواند."
        );

        foreach (['meta', 'links'] as $block) {
            if (! isset($body[$block])) {
                continue;
            }

            $this->assertEqualsCanonicalizing(
                array_keys($schema['properties'][$block]['properties'] ?? []),
                array_keys($body[$block]),
                "{$path}: کلیدهای `{$block}` با api.json نمی‌خواند."
            );
        }
    }

    /** @return array<string, mixed> */
    private function documentedSchema(string $path, int $status): array
    {
        $spec = json_decode((string) file_get_contents(base_path('api.json')), true);

        $schema = $spec['paths'][$path]['get']['responses'][$status]['content']['application/json']['schema'] ?? null;

        if ($schema === null) {
            $this->fail("GET {$path} در api.json پاسخِ {$status} ندارد.");
        }

        return $schema;
    }
}
