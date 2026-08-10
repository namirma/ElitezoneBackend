<?php

namespace Tests\Feature\Content;

use App\Models\PromoOverlay;
use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use App\Services\Content\OverlayAudienceEvaluator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تغییرِ نامِ `expert` → `elite_yar` نباید هیچ اورلیِ موجودی را بی‌صدا خاموش کند.
 *
 * چرا این تست وجود دارد: شرطِ مخاطب در دیتابیس به‌صورت JSON ذخیره شده
 * (`{"type":"persona","value":["expert"]}`). اگر ارزیاب فقط واژگانِ جدید را
 * تولید کند و مقدارِ ذخیره‌شده را ترجمه نکند، آن قاعده با هیچ کاربری مطابقت
 * نمی‌کند — بدونِ هیچ خطا، هیچ لاگی، و هیچ نشانه‌ای در پنل. ادمین فقط می‌بیند
 * که اورلی «دیگر نمایش داده نمی‌شود».
 */
class OverlayPersonaAliasTest extends TestCase
{
    use DatabaseTransactions;

    private OverlayAudienceEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = app(OverlayAudienceEvaluator::class);
    }

    /** اورلی‌ای که فقط یک شرطِ persona دارد. */
    private function overlayForPersonas(array $personas): PromoOverlay
    {
        return new PromoOverlay([
            PromoOverlay::TITLE => 'تست',
            PromoOverlay::AUDIENCE => [
                'op' => 'and',
                'rules' => [
                    ['type' => PromoOverlay::RULE_PERSONA, 'value' => $personas],
                ],
            ],
        ]);
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role]);
        $user = User::factory()->create();
        $user->addRole($role);

        return $user->fresh();
    }

    private function userWithTypeSlug(string $slug): User
    {
        // `user_types.icon` ستونی NOT NULL بدونِ default است — بدونش insert
        // با 1364 می‌شکند.
        $type = UserType::firstOrCreate(
            [UserType::SLUG => $slug],
            [UserType::NAME => $slug, UserType::ICON => '']
        );

        return User::factory()->create([User::TYPE_ID => $type->id])->fresh();
    }

    #[Test]
    public function the_new_elite_yar_role_alone_resolves_to_the_elite_yar_persona(): void
    {
        $user = $this->userWithRole('elite_yar');

        $this->assertTrue(
            $this->evaluator->matches($this->overlayForPersonas(['elite_yar']), $user),
            'نقشِ جدیدِ elite_yar باید به‌تنهایی کافی باشد.'
        );
    }

    #[Test]
    public function a_rule_saved_with_the_old_expert_value_still_matches(): void
    {
        $user = $this->userWithRole('elite_yar');

        $this->assertTrue(
            $this->evaluator->matches($this->overlayForPersonas(['expert']), $user),
            'قاعده‌ی ذخیره‌شده‌ی قدیمی نباید بی‌صدا از کار بیفتد.'
        );
    }

    #[Test]
    public function a_legacy_engineer_user_still_matches_the_elite_yar_persona(): void
    {
        $user = $this->userWithRole('engineer');

        $this->assertTrue(
            $this->evaluator->matches($this->overlayForPersonas(['elite_yar']), $user),
            'کاربرانِ پیش از مهاجرت هنوز نقشِ engineer/contractor دارند.'
        );
    }

    #[Test]
    public function a_legacy_expert_type_slug_still_matches(): void
    {
        $user = $this->userWithTypeSlug('expert');

        $this->assertTrue(
            $this->evaluator->matches($this->overlayForPersonas(['elite_yar']), $user),
            'type.slugِ قدیمی هنوز باید الیت یار حساب شود.'
        );
    }

    #[Test]
    public function the_combined_persona_is_not_matched_by_a_pure_employer_rule(): void
    {
        // مهم‌ترین حالتِ درستی: «الیت یار و کارفرما» خودش الیت یار است، پس
        // اورلیِ «الیت یارت را انتخاب کن» نباید به او نشان داده شود.
        Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'elite_yar']);
        Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'employer']);
        $user = User::factory()->create();
        $user->addRole('elite_yar');
        $user->addRole('employer');
        $user = $user->fresh();

        $this->assertFalse(
            $this->evaluator->matches($this->overlayForPersonas(['employer']), $user),
            'کاربرِ ترکیبی نباید با قاعده‌ی «کارفرمای خالص» مطابقت کند.'
        );

        $this->assertTrue(
            $this->evaluator->matches(
                $this->overlayForPersonas(['expert_and_employer']),
                $user
            ),
            'و باید با قاعده‌ی ترکیبیِ ذخیره‌شده‌ی قدیمی مطابقت کند.'
        );
    }

    #[Test]
    public function has_role_accepts_both_vocabularies(): void
    {
        $overlay = new PromoOverlay([
            PromoOverlay::TITLE => 'تست',
            PromoOverlay::AUDIENCE => [
                'op' => 'and',
                'rules' => [['type' => PromoOverlay::RULE_HAS_ROLE, 'value' => true]],
            ],
        ]);

        $this->assertTrue(
            $this->evaluator->matches($overlay, $this->userWithRole('elite_yar')),
            'نقشِ جدید باید «نقش انتخاب شده» حساب شود.'
        );

        // رگرسیونِ `+` در برابر array_merge: اگر آرایه‌ها با `+` ادغام شوند،
        // 'employer' و 'supplier' بی‌صدا حذف می‌شوند و این ادعا شکست می‌خورد.
        $this->assertTrue(
            $this->evaluator->matches($overlay, $this->userWithRole('employer')),
            'کارفرما هم باید «نقش انتخاب شده» حساب شود.'
        );

        $this->assertTrue(
            $this->evaluator->matches($overlay, $this->userWithTypeSlug('supplier')),
            'سوپلایر (از راهِ type.slug) هم باید «نقش انتخاب شده» حساب شود.'
        );
    }
}
