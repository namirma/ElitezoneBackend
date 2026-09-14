<?php

namespace Tests\Feature\Content;

use App\Models\Banner;
use App\Models\PageBlock;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Content\PageBlockSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * بنرها به سایت وصل شدند (۲۰۲۶-۰۹-۱۴). این سوئیت سه قرارداد را پین می‌کند:
 *
 *   ۱. جایگاه فقط از `Banner::POSITIONS` — یک بنرِ بی‌جایگاه یا با جایگاهِ
 *      ناشناخته هیچ‌جا رندر نمی‌شود و «ذخیره‌ی موفقِ بی‌اثر» همان چیزی است
 *      که این کار برای پایان‌دادن به آن انجام شد.
 *   ۲. بنرِ دسته‌بندی بدونِ `category_id` یتیم است (۴۲۲)، و عوض‌کردنِ جایگاه
 *      دسته را با خودش نمی‌کشد.
 *   ۳. بلوکِ `banner_slot` صفحه‌ساز همان فهرست را می‌پذیرد — نه بیشتر، نه کمتر.
 */
class BannerPositionsTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(Banner::CACHE_KEY);
        Storage::fake('public');

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($this->admin);
        Sanctum::actingAs($this->admin);
    }

    private function category(): ProductCategory
    {
        $u = Str::random(6);

        return ProductCategory::create(['name' => "دسته {$u}", 'slug' => "c-{$u}", 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'بنر',
            'image' => UploadedFile::fake()->image('b.png', 640, 360),
            'position' => 'home_main',
        ], $overrides);
    }

    #[Test]
    public function an_unknown_position_is_a_422_not_a_saved_banner_nobody_sees(): void
    {
        $this->post('/api/admin/banners', $this->payload(['position' => 'sidebar']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('position');

        $this->post('/api/admin/banners', $this->payload(['position' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('position');
    }

    #[Test]
    public function every_declared_position_is_accepted(): void
    {
        foreach (array_keys(Banner::POSITIONS) as $position) {
            $extra = $position === Banner::POSITION_CATEGORY ? ['category_id' => $this->category()->id] : [];

            $this->post('/api/admin/banners', $this->payload(['position' => $position] + $extra))
                ->assertCreated()
                ->assertJsonPath('data.position', $position);
        }
    }

    #[Test]
    public function a_category_banner_without_a_category_is_refused(): void
    {
        $this->post('/api/admin/banners', $this->payload(['position' => Banner::POSITION_CATEGORY]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    #[Test]
    public function a_category_banner_carries_its_category_and_subtitle_to_the_public_endpoint(): void
    {
        $category = $this->category();

        $id = $this->post('/api/admin/banners', $this->payload([
            'position' => Banner::POSITION_CATEGORY,
            'category_id' => $category->id,
            'subtitle' => 'زیرعنوان',
        ]))->assertCreated()->json('data.id');

        $public = collect($this->getJson('/api/banners?position=category_banner')->assertOk()->json('data'))
            ->firstWhere('id', $id);

        $this->assertSame($category->id, $public['category_id']);
        $this->assertSame('زیرعنوان', $public['subtitle']);
        // URLِ دیسک (با `Storage::fake` بدونِ هاست)، نه مسیرِ خامِ `banners/…`.
        $this->assertStringStartsWith('/storage/banners/', $public['image']);
    }

    #[Test]
    public function moving_a_banner_off_the_category_position_drops_its_category(): void
    {
        $category = $this->category();
        $banner = Banner::create([
            Banner::TITLE => 'x', Banner::IMAGE => 'banners/x.png',
            Banner::POSITION => Banner::POSITION_CATEGORY, Banner::CATEGORY_ID => $category->id,
        ]);

        $this->putJson("/api/admin/banners/{$banner->id}", ['position' => 'checkout_top'])
            ->assertOk()
            ->assertJsonPath('data.category_id', null);

        $this->assertNull($banner->fresh()->{Banner::CATEGORY_ID});
    }

    #[Test]
    public function deleting_a_category_orphans_its_banner_instead_of_deleting_it(): void
    {
        $category = $this->category();
        $banner = Banner::create([
            Banner::TITLE => 'x', Banner::IMAGE => 'banners/x.png',
            Banner::POSITION => Banner::POSITION_CATEGORY, Banner::CATEGORY_ID => $category->id,
        ]);

        $category->delete();

        $this->assertNull($banner->fresh()->{Banner::CATEGORY_ID});
    }

    #[Test]
    public function the_banner_slot_block_offers_exactly_the_banner_positions(): void
    {
        $options = (new PageBlockSchema)->for('banner_slot')['fields']['position']['options'];

        $this->assertSame(Banner::positionOptions(), $options);
        $this->assertSame(array_keys(Banner::POSITIONS), array_column($options, 'value'));
    }

    #[Test]
    public function a_banner_slot_block_rejects_a_position_the_banner_form_would_not_accept(): void
    {
        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'banner_slot', 'sort_order' => 99,
            'data' => ['position' => 'sidebar'],
        ])->assertStatus(422)->assertJsonValidationErrors('data.position');

        $this->postJson('/api/admin/page-blocks', [
            'page' => 'home', 'type' => 'banner_slot', 'sort_order' => 99,
            'data' => ['position' => 'home_banners', 'style' => ['gap' => '20']],
        ])->assertCreated();

        $this->assertDatabaseHas(PageBlock::TABLE, [PageBlock::TYPE => 'banner_slot']);
    }

    #[Test]
    public function a_category_subtitle_round_trips_and_its_image_is_a_url(): void
    {
        $category = $this->category();
        $category->update([ProductCategory::IMAGE => 'categories/x.jpg']);

        $this->putJson("/api/admin/categories/{$category->id}", ['subtitle' => 'مصالح برتر'])
            ->assertOk()
            ->assertJsonPath('data.subtitle', 'مصالح برتر');

        $public = collect($this->getJson('/api/products/categories')->assertOk()->json('data'))
            ->firstWhere('id', $category->id);

        $this->assertSame('مصالح برتر', $public['subtitle']);
        $this->assertSame('/storage/categories/x.jpg', $public['image']);
    }
}
