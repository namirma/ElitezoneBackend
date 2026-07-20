<?php

namespace Tests\Feature\Admin;

use App\Models\Banner;
use App\Models\Coupon;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Pins the round-2 hardening contracts: soft-delete + restore for content,
 * the ?trashed=1 recycle-bin filter, audit-trail rows for sensitive admin
 * actions (settings/coupons/admins), and the config-driven admin IP allowlist
 * (empty list = disabled, non-matching IP = 403).
 */
class AdminHardeningRound2Test extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $user;
    }

    // ── Soft delete + restore ─────────────────────────────────────────────

    #[Test]
    public function deleting_a_banner_is_soft_and_restore_brings_it_back(): void
    {
        Sanctum::actingAs($this->admin());

        $banner = Banner::create([Banner::TITLE => 'تست', Banner::IMAGE => 'banners/x.png']);

        $this->deleteJson("/api/admin/banners/{$banner->id}")->assertOk();

        $this->assertSoftDeleted('banners', ['id' => $banner->id]);

        // در سطل بازیافت دیده می‌شود
        $trashed = $this->getJson('/api/admin/banners?trashed=1')->assertOk()->json('data');
        $this->assertContains($banner->id, array_column($trashed, 'id'));

        $this->postJson("/api/admin/banners/{$banner->id}/restore")->assertOk();
        $this->assertNull($banner->fresh()->deleted_at);
    }

    #[Test]
    public function deleting_a_page_is_soft_and_it_disappears_from_public(): void
    {
        Sanctum::actingAs($this->admin());

        $page = Page::create([Page::TITLE => 'حذفی', Page::SLUG => 'del-'.Str::random(6), Page::BODY => 'x']);

        $this->deleteJson("/api/admin/pages/{$page->id}")->assertOk();

        $this->assertSoftDeleted('pages', ['id' => $page->id]);
        $this->getJson('/api/pages/'.$page->slug)->assertNotFound();

        $this->postJson("/api/admin/pages/{$page->id}/restore")->assertOk();
        $this->getJson('/api/pages/'.$page->slug)->assertOk();
    }

    // ── Audit trail ───────────────────────────────────────────────────────

    #[Test]
    public function updating_settings_writes_an_audit_row_with_the_changed_keys(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings', ['settings' => ['site_title' => 'الیت زون']])->assertOk();

        $activity = Activity::query()->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, (int) $activity->causer_id);
        $this->assertContains('site_title', $activity->properties['keys'] ?? []);
    }

    #[Test]
    public function coupon_creation_writes_an_audit_row(): void
    {
        Sanctum::actingAs($this->admin());

        $code = 'AUD-'.Str::random(5);
        $this->postJson('/api/admin/coupons', [
            Coupon::CODE => $code,
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 50_000,
        ])->assertCreated();

        $this->assertTrue(
            Activity::query()->where('properties->code', $code)->exists()
        );
    }

    #[Test]
    public function admin_permission_change_writes_an_audit_row(): void
    {
        Sanctum::actingAs($this->admin());

        $target = $this->admin();

        $this->putJson("/api/admin/admins/{$target->id}", ['permissions' => ['manage-content']])->assertOk();

        $activity = Activity::query()
            ->where('subject_id', $target->id)
            ->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame(['manage-content'], $activity->properties['permissions'] ?? null);
    }

    // ── Admin IP allowlist ────────────────────────────────────────────────

    #[Test]
    public function empty_ip_whitelist_leaves_admin_panel_accessible(): void
    {
        config(['security.admin_ip_whitelist' => []]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/dashboard')->assertOk();
    }

    #[Test]
    public function non_whitelisted_ip_is_blocked_with_403(): void
    {
        config(['security.admin_ip_whitelist' => ['203.0.113.7']]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    #[Test]
    public function whitelisted_cidr_range_is_allowed(): void
    {
        // IP پیش‌فرض تست‌های لاراول 127.0.0.1 است — رنج لوپ‌بک مجاز می‌شود
        config(['security.admin_ip_whitelist' => ['127.0.0.0/8']]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/dashboard')->assertOk();
    }
}
