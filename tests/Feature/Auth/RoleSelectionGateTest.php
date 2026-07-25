<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * گیتِ انتخاب نقش روی checkout.
 *
 * تا پیش از این کدِ مرده بود: گارد `type_id !== null` را چک می‌کرد و
 * `AuthController::register` همیشه type_id پیش‌فرض `customer` می‌گذاشت، پس گیت
 * هرگز اجرا نمی‌شد. حالا `customer` به‌عنوان «بی‌نقش» شناخته می‌شود و گیت
 * opt-in است تا فرانت فرصتِ هندل‌کردنِ ۴۰۳ را داشته باشد.
 */
class RoleSelectionGateTest extends TestCase
{
    use DatabaseTransactions;

    private function userOfType(string $slug, string $name = 'نوع'): User
    {
        $type = UserType::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'icon' => 'user'],
        );

        return User::factory()->create([User::TYPE_ID => $type->id]);
    }

    private function placeOrder(): TestResponse
    {
        // بدنه عمداً خالی است — گیت باید *قبل* از اعتبارسنجی بزند.
        return $this->postJson('/api/checkout', []);
    }

    #[Test]
    public function the_gate_is_off_by_default(): void
    {
        Sanctum::actingAs($this->userOfType('customer', 'مشتری'));

        // بدون نقش، ولی چون گیت خاموش است نباید ۴۰۳ بگیرد — درخواست به
        // اعتبارسنجی می‌رسد (۴۲۲) نه به گیت.
        $this->placeOrder()->assertStatus(422);
    }

    #[Test]
    public function the_placeholder_customer_type_does_not_count_as_a_selected_role(): void
    {
        config(['auth.role_selection_gate_enabled' => true]);

        Sanctum::actingAs($this->userOfType('customer', 'مشتری'));

        $this->placeOrder()
            ->assertForbidden()
            ->assertJsonPath('code', 'ROLE_NOT_SELECTED')
            ->assertJsonPath('action', 'force_role_selection')
            ->assertJsonPath('meta.role_selected', false);
    }

    #[Test]
    public function an_onboarding_user_type_passes_the_gate(): void
    {
        config(['auth.role_selection_gate_enabled' => true]);

        Sanctum::actingAs($this->userOfType('employer', 'کارفرما'));

        $this->placeOrder()->assertStatus(422);
    }

    #[Test]
    public function a_laratrust_role_passes_the_gate_even_with_the_placeholder_type(): void
    {
        config(['auth.role_selection_gate_enabled' => true]);

        // کاربری که آنبردینگ را تمام کرده اما type_idش عقب مانده نباید قفل شود.
        $user = $this->userOfType('customer', 'مشتری');
        $user->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'Employer']));

        Sanctum::actingAs($user);

        $this->placeOrder()->assertStatus(422);
    }

    #[Test]
    public function the_window_expires_after_the_configured_hours(): void
    {
        config([
            'auth.role_selection_gate_enabled' => true,
            'auth.role_selection_window_hours' => 2,
        ]);

        $user = $this->userOfType('customer', 'مشتری');
        $user->forceFill([User::CREATED_AT => now()->subHours(5)])->save();

        Sanctum::actingAs($user);

        $this->placeOrder()
            ->assertForbidden()
            ->assertJsonPath('code', 'REGISTRATION_WINDOW_EXPIRED')
            ->assertJsonPath('action', 'show_limited_attachments')
            ->assertJsonPath('meta.registration_window_open', false)
            ->assertJsonPath('meta.window_hours', 2);
    }

    #[Test]
    public function hours_passed_is_an_integer_on_both_carbon_versions(): void
    {
        config(['auth.role_selection_gate_enabled' => true]);

        $user = $this->userOfType('customer', 'مشتری');
        $user->forceFill([User::CREATED_AT => now()->subMinutes(95)])->save();

        Sanctum::actingAs($user);

        $meta = $this->placeOrder()->assertForbidden()->json('meta');

        $this->assertIsInt($meta['hours_passed']);
        $this->assertSame(1, $meta['hours_passed']);
        $this->assertTrue($meta['registration_window_open']);
    }
}
