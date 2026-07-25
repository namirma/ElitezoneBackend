<?php

namespace Tests\Feature\Admin;

use App\Models\Badge;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class AdminBadgeTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    #[Test]
    public function guest_cannot_list_badges(): void
    {
        $this->getJson('/api/admin/badges')->assertUnauthorized();
    }

    #[Test]
    public function non_admin_cannot_list_badges(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/badges')->assertForbidden();
    }

    #[Test]
    public function admin_can_create_a_badge_with_icon(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());

        $code = 'test_'.uniqid();
        $response = $this->postJson('/api/admin/badges', [
            'name' => 'نشان تست',
            'code' => $code,
            'icon' => UploadedFile::fake()->image('badge.png', 120, 120),
        ]);

        $response->assertCreated()->assertJsonPath('status', true);
        $this->assertDatabaseHas('badges', ['code' => $code, 'name' => 'نشان تست']);

        $icon = Badge::where('code', $code)->value('icon');
        $this->assertNotNull($icon);
        Storage::disk('public')->assertExists($icon);
    }

    #[Test]
    public function creating_a_badge_with_duplicate_code_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $existing = Badge::create(['name' => 'x', 'code' => 'dup_'.uniqid()]);

        $this->postJson('/api/admin/badges', ['name' => 'y', 'code' => $existing->code])
            ->assertStatus(422);
    }

    #[Test]
    public function admin_can_update_then_delete_a_badge(): void
    {
        Sanctum::actingAs($this->admin());
        $badge = Badge::create(['name' => 'قدیمی', 'code' => 'upd_'.uniqid()]);

        $this->putJson("/api/admin/badges/{$badge->id}", ['name' => 'جدید'])
            ->assertOk()
            ->assertJsonPath('data.name', 'جدید');

        $this->deleteJson("/api/admin/badges/{$badge->id}")->assertOk();
        $this->assertDatabaseMissing('badges', ['id' => $badge->id]);
    }

    /**
     * آیکون‌ها روی دیسک public می‌نشینند و از همان origin سرو می‌شوند، پس یک SVG
     * حاوی <script> برابر است با XSS ذخیره‌شده. UpdateBadgeRequest اشتباهاً svg
     * را مجاز کرده بود در حالی که StoreBadgeRequest نکرده بود؛ هر دو مسیر باید
     * ردش کنند و لیست mimeها باید یکی بماند.
     */
    #[Test]
    public function svg_icons_are_rejected_on_both_create_and_update(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());

        $svg = UploadedFile::fake()->createWithContent(
            'x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->postJson('/api/admin/badges', [
            'name' => 'نشان تست',
            'code' => 'svg_'.uniqid(),
            'icon' => $svg,
        ])->assertStatus(422)->assertJsonValidationErrors('icon');

        $badge = Badge::create(['name' => 'قدیمی', 'code' => 'svg2_'.uniqid()]);

        $this->postJson("/api/admin/badges/{$badge->id}", [
            'name' => 'جدید',
            'icon' => $svg,
        ])->assertStatus(422)->assertJsonValidationErrors('icon');

        $this->assertNull($badge->fresh()->icon);
    }
}
