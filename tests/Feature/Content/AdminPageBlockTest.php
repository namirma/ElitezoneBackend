<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class AdminPageBlockTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($this->admin);
    }

    #[Test]
    public function a_guest_cannot_list_blocks(): void
    {
        $this->getJson('/api/admin/page-blocks?page=home')->assertUnauthorized();
    }

    #[Test]
    public function an_admin_creates_a_block(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home',
            'type' => 'story_card',
            'sort_order' => 1,
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ])->assertCreated();

        $this->assertDatabaseHas(PageBlock::TABLE, [PageBlock::TYPE => 'story_card']);
    }

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'not_a_block', 'data' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    #[Test]
    public function a_required_field_from_the_schema_is_enforced(): void
    {
        Sanctum::actingAs($this->admin);

        // story_card می‌گوید `title` الزامی است — قاعده از رجیستری می‌آید،
        // نه از فهرستی که دستی در FormRequest نوشته شده باشد.
        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'story_card',
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.title');
    }

    #[Test]
    public function the_schema_endpoint_lists_every_registered_type(): void
    {
        Sanctum::actingAs($this->admin);

        $types = array_keys($this->getJson('/api/admin/page-blocks/schema')->assertOk()->json('data'));

        $this->assertContains('story_card', $types);
        $this->assertContains('card_grid', $types);
    }

    /**
     * نگهبانِ رگرسیون: `UpdatePageBlockRequest` وقتی `type` در بدنه نیست، آن را
     * از نوعِ ذخیره‌شده روی ردیف می‌گیرد. اگر آن fallback بشکند (مثلاً به دلیلِ
     * ناهم‌نامیِ پارامترِ روت — همان باگِ «route-parameter gotcha» که قبلاً در
     * `UpdateMenuItemRequest` رخ داد)، `PageBlockSchema::rulesFor()` رشته‌ی
     * خالی می‌گیرد، آرایه‌ی خالی برمی‌گرداند، و `data` بدونِ هیچ اعتبارسنجی‌ای
     * مستقیم روی یک صفحه‌ی عمومی می‌نشیند. این تست دقیقاً همان مسیر را می‌بندد.
     */
    #[Test]
    public function updating_without_a_type_still_validates_against_the_stored_type(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        // `title` را حذف می‌کنیم و `type` را اصلاً نمی‌فرستیم — قاعده باید از
        // نوعِ ذخیره‌شده (story_card) بیاید، نه از بدنه‌ی درخواست.
        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.title');
    }

    #[Test]
    public function an_admin_updates_a_block(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوانِ قدیم'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'data' => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوانِ جدید'],
        ])->assertOk();

        $this->assertSame('عنوانِ جدید', $block->fresh()->{PageBlock::DATA}['title']);
    }

    #[Test]
    public function updating_with_an_unknown_type_in_the_body_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $block = PageBlock::create([
            PageBlock::PAGE => PageBlock::PAGE_HOME,
            PageBlock::TYPE => 'story_card',
            PageBlock::SORT_ORDER => 1,
            PageBlock::DATA => ['image' => 'blocks/a.webp', 'alt' => 'الف', 'title' => 'عنوان'],
        ]);

        $this->putJson("/api/admin/page-blocks/{$block->id}", [
            'type' => 'not_a_block',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }
}
