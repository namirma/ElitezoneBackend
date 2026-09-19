<?php

namespace Tests\Feature\Content;

use App\Models\PromoOverlay;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * CRUDِ اورلی‌های ترغیب از بک‌آفیس.
 *
 * این تست دقیقاً همان payloadی را می‌فرستد که فرمِ بک‌آفیس می‌سازد — از جمله
 * فیلدهای ساختاردار (buttons/audience/routes) و مقادیرِ خالیِ اختیاری. هدفش
 * این است که «ذخیره نمی‌شود» دیگر یک گزارشِ دستی نباشد.
 */
class PromoOverlayAdminTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']);
        $this->admin = User::factory()->create();
        $this->admin->addRole('admin');
        $this->grantAllAdminPermissions($this->admin);
        Sanctum::actingAs($this->admin);
    }

    /** payloadی که فرمِ بک‌آفیس واقعاً می‌سازد. */
    private function formPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'اورلی آزمایشی',
            'body' => 'متن توضیح',
            'buttons' => [
                ['label' => 'تعیین نقش', 'style' => 'primary', 'action' => 'navigate', 'value' => '/role'],
                ['label' => 'بستن', 'style' => 'secondary', 'action' => 'dismiss', 'value' => ''],
            ],
            'audience' => [
                'op' => 'and',
                'rules' => [
                    ['type' => 'auth_state', 'value' => 'authenticated'],
                    ['type' => 'has_role', 'value' => false],
                ],
            ],
            'routes' => ['include' => ['*'], 'exclude' => ['/role*']],
            'delay_seconds' => 30,
            'frequency' => 'day',
            'priority' => 5,
            'is_active' => true,
        ], $overrides);
    }

    #[Test]
    public function an_admin_can_create_an_overlay_with_structured_fields(): void
    {
        $res = $this->postJson('/api/admin/promo-overlays', $this->formPayload());

        $res->assertCreated();

        $id = $res->json('data.id');
        $overlay = PromoOverlay::findOrFail($id);

        // فیلدهای ساختاردار باید کامل ذخیره شوند — نه رشته‌ی خالی، نه null
        $this->assertCount(2, $overlay->buttons, 'buttons persisted');
        $this->assertSame('/role', $overlay->buttons[0]['value']);
        $this->assertSame('and', $overlay->audience['op']);
        $this->assertCount(2, $overlay->audience['rules'], 'audience rules persisted');
        $this->assertSame(['*'], $overlay->routes['include']);
        $this->assertSame(30, $overlay->delay_seconds);
        $this->assertSame(5, $overlay->priority);
    }

    #[Test]
    public function an_admin_can_update_an_overlay(): void
    {
        $created = $this->postJson('/api/admin/promo-overlays', $this->formPayload())->json('data.id');

        $res = $this->putJson("/api/admin/promo-overlays/{$created}", $this->formPayload([
            'title' => 'عنوان ویرایش‌شده',
            'buttons' => [
                ['label' => 'فقط یک دکمه', 'style' => 'link', 'action' => 'dismiss', 'value' => ''],
            ],
        ]));

        $res->assertOk();

        $overlay = PromoOverlay::findOrFail($created);
        $this->assertSame('عنوان ویرایش‌شده', $overlay->title);
        $this->assertCount(1, $overlay->buttons, 'buttons replaced on update');
    }

    /**
     * فرمِ بک‌آفیس برای فیلدهای عددیِ خالی رشته‌ی "" می‌فرستد (نه null).
     * قانونِ `nullable|integer` رشته‌ی خالی را رد می‌کند، پس اگر پاک نشود کلِ
     * ذخیره ۴۲۲ می‌گیرد — همان «ویرایش نمی‌شود»ی که گزارش شد.
     */
    #[Test]
    public function empty_optional_numbers_do_not_break_the_save(): void
    {
        $res = $this->postJson('/api/admin/promo-overlays', $this->formPayload([
            'frequency' => 'day',
            'frequency_ttl_hours' => '',
            'priority' => '',
            'body' => '',
        ]));

        $res->assertCreated();
    }

    #[Test]
    public function ttl_frequency_requires_hours(): void
    {
        $this->postJson('/api/admin/promo-overlays', $this->formPayload([
            'frequency' => 'ttl',
            'frequency_ttl_hours' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors('frequency_ttl_hours');
    }

    #[Test]
    public function a_navigate_button_must_carry_an_internal_path(): void
    {
        $this->postJson('/api/admin/promo-overlays', $this->formPayload([
            'buttons' => [
                ['label' => 'برو', 'style' => 'primary', 'action' => 'navigate', 'value' => 'role'],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('buttons.0.value');
    }

    #[Test]
    public function an_unknown_audience_rule_type_is_rejected(): void
    {
        $this->postJson('/api/admin/promo-overlays', $this->formPayload([
            'audience' => ['op' => 'and', 'rules' => [['type' => 'made_up', 'value' => 1]]],
        ]))->assertStatus(422);
    }

    #[Test]
    public function a_system_overlay_cannot_be_deleted_but_can_be_edited(): void
    {
        $system = PromoOverlay::create($this->formPayload([
            'key' => PromoOverlay::KEY_WELCOME,
            'title' => 'سیستمی',
        ]));

        $this->deleteJson("/api/admin/promo-overlays/{$system->id}")->assertStatus(422);

        $this->putJson("/api/admin/promo-overlays/{$system->id}", $this->formPayload([
            'title' => 'سیستمیِ ویرایش‌شده',
        ]))->assertOk();

        $this->assertSame('سیستمیِ ویرایش‌شده', $system->fresh()->title);
    }
}
