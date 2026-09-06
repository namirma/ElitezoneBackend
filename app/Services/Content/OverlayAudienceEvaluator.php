<?php

namespace App\Services\Content;

use App\Models\EmployerEliteYAR;
use App\Models\Order;
use App\Models\PromoOverlay;
use App\Models\User;
use App\Models\UserType;

/**
 * ارزیابیِ «آیا این اورلی به این کاربر مربوط است؟».
 *
 * درختِ شرط را ادمین در بک‌آفیس می‌سازد و اینجا اجرا می‌شود. عمداً سمتِ سرور
 * است، نه فرانت: بعضی شرط‌ها (تعداد سفارش، داشتنِ الیت یارِ تأییدشده) داده‌ی
 * دیتابیس می‌خواهند و فرستادنشان به مرورگر یعنی افشای بی‌دلیلِ اطلاعات و یک
 * قراردادِ شکننده. فرانت فقط زمان‌بندی/مسیر/دوره‌ی تکرار را مدیریت می‌کند.
 *
 * شکلِ درخت:
 *   {"op":"and","rules":[ {...}, {"op":"or","rules":[{...},{...}]} ]}
 *
 * `audience` خالی/null یعنی «همه» — که برای یک اورلیِ صرفاً تبلیغاتی درست است.
 */
class OverlayAudienceEvaluator
{
    /** داده‌های مشتقِ کاربر که ممکن است چند شرط به آن نیاز داشته باشند. */
    private array $memo = [];

    public function matches(PromoOverlay $overlay, ?User $user): bool
    {
        $this->memo = [];

        $tree = $overlay->{PromoOverlay::AUDIENCE};

        if (empty($tree) || empty($tree['rules'])) {
            return true;
        }

        return $this->evaluateNode($tree, $user);
    }

    private function evaluateNode(array $node, ?User $user): bool
    {
        $op = strtolower((string) ($node['op'] ?? 'and'));
        $rules = $node['rules'] ?? [];

        if (! is_array($rules) || $rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            // گرهِ تودرتو (and/or) در برابر یک شرطِ برگ
            $result = isset($rule['rules'])
                ? $this->evaluateNode($rule, $user)
                : $this->evaluateRule($rule, $user);

            if ($op === 'or' && $result) {
                return true;
            }
            if ($op !== 'or' && ! $result) {
                return false;
            }
        }

        // and → همه درست بودند | or → هیچ‌کدام درست نبود
        return $op !== 'or';
    }

    private function evaluateRule(array $rule, ?User $user): bool
    {
        $type = $rule['type'] ?? null;
        $value = $rule['value'] ?? null;

        return match ($type) {
            PromoOverlay::RULE_AUTH_STATE => $value === 'guest' ? $user === null : $user !== null,

            PromoOverlay::RULE_HAS_ROLE => $user !== null
                && $this->hasSelectedRole($user) === (bool) $value,

            PromoOverlay::RULE_PERSONA => $user !== null
                && in_array($this->persona($user), $this->normalizePersonas((array) $value), true),

            PromoOverlay::RULE_HAS_ELITE_YAR => $user !== null
                && $this->hasEliteYAR($user) === (bool) $value,

            PromoOverlay::RULE_ORDERS_COUNT => $user !== null
                && $this->compare(
                    $this->ordersCount($user),
                    (string) ($rule['operator'] ?? '='),
                    (float) $value
                ),

            // نوعِ ناشناخته هرگز مطابقت نمی‌کند — «نمی‌دانم» نباید به «آری»
            // ترجمه شود، وگرنه یک تایپو اورلی را به همه نشان می‌دهد.
            default => false,
        };
    }

    private function compare(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            '=' => $left == $right,
            '!=' => $left != $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => false,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // مشتقاتِ کاربر — همان قواعدی که بقیه‌ی بک‌اند استفاده می‌کند
    // ─────────────────────────────────────────────────────────────────────────

    /** آینه‌ی `EnsureRoleIsSelected::hasSelectedRole()`. */
    private function hasSelectedRole(User $user): bool
    {
        return $this->memo['has_role'] ??= (function () use ($user): bool {
            // ⚠️ array_merge، نه `+`: روی آرایه‌های با کلیدِ عددی، `+` کلیدهای
            // تکراریِ سمتِ راست را دور می‌ریزد و اینجا 'employer'/'supplier'
            // بی‌صدا حذف می‌شدند.
            if ($user->hasRole(array_merge(PromoOverlay::ELITE_YAR_ROLES, ['employer', 'supplier']))) {
                return true;
            }

            return UserType::query()
                ->whereKey($user->{User::TYPE_ID})
                ->whereIn(UserType::SLUG, array_merge(PromoOverlay::ELITE_YAR_SLUGS, ['employer', 'supplier']))
                ->exists();
        })();
    }

    /**
     * آینه‌ی `resolvePersona()`ِ فرانت — نقشِ Laratrust **یا** type.slug.
     *
     * حالتِ ترکیبی روی type.slugِ الیت‌یار + نقشِ employer ذخیره می‌شود
     * (UserTypeی برای حالتِ ترکیبی وجود ندارد)، پس هر دو منبع لازم است.
     *
     * خروجی همیشه واژگانِ **جدید** است (`elite_yar`)؛ مقدارهای ذخیره‌شده‌ی قدیمی
     * در قاعده‌ها با `normalizePersona()` نرمال می‌شوند، نه اینجا — وگرنه با
     * تغییرِ نامِ نقش در بک‌اند، هر قاعده‌ای که ادمین قبلاً ساخته بی‌صدا از کار
     * می‌افتاد.
     */
    private function persona(User $user): string
    {
        return $this->memo['persona'] ??= (function () use ($user): string {
            $user->loadMissing(['roles', 'type']);
            $roles = $user->roles->pluck('name')->map(fn ($n) => strtolower((string) $n));
            $slug = strtolower((string) optional($user->type)->{UserType::SLUG});

            $isEliteYAR = $roles->intersect(PromoOverlay::ELITE_YAR_ROLES)->isNotEmpty()
                || in_array($slug, PromoOverlay::ELITE_YAR_SLUGS, true);
            $isEmployer = $roles->contains('employer') || $slug === 'employer';
            $isSupplier = $roles->contains('supplier') || $slug === 'supplier';

            return match (true) {
                $isEliteYAR && $isEmployer => PromoOverlay::PERSONA_ELITE_YAR_EMPLOYER,
                $isEliteYAR => PromoOverlay::PERSONA_ELITE_YAR,
                $isEmployer => PromoOverlay::PERSONA_EMPLOYER,
                $isSupplier => PromoOverlay::PERSONA_SUPPLIER,
                default => 'unknown',
            };
        })();
    }

    /**
     * مقدارهای قاعده را به واژگانِ جدید ترجمه می‌کند.
     *
     * قاعده‌هایی که ادمین پیش از تغییرِ نام ساخته `expert` را ذخیره کرده‌اند؛
     * بدونِ این ترجمه آن قاعده‌ها با هیچ کاربری مطابقت نمی‌کردند و اورلی بی‌صدا
     * از نمایش می‌افتاد — بدترین نوعِ خرابی، چون هیچ خطایی هم تولید نمی‌کند.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizePersonas(array $values): array
    {
        return array_map(
            fn ($v) => PromoOverlay::PERSONA_ALIASES[strtolower((string) $v)] ?? strtolower((string) $v),
            $values
        );
    }

    private function hasEliteYAR(User $user): bool
    {
        return $this->memo['has_elite_yar'] ??= $user->technicalEliteYARs()
            ->wherePivot(EmployerEliteYAR::STATUS, EmployerEliteYAR::STATUS_APPROVED)
            ->exists();
    }

    private function ordersCount(User $user): float
    {
        return $this->memo['orders_count'] ??= (float) Order::query()
            ->where(Order::PLACER_USER_ID, $user->{User::ID})
            ->count();
    }
}
