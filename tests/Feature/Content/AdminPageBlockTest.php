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
}
