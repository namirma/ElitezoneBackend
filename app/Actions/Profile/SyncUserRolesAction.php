<?php

namespace App\Actions\Profile;

use App\Models\ContractorProfile;
use App\Models\EmployerProfile;
use App\Models\EngineerProfile;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Adds / removes Laratrust roles and creates / deletes the corresponding
 * role-profile records based on the user's new role selection.
 *
 * Extracted from ProfileController::setUserType so it is independently
 * testable and single-purpose.
 */
final class SyncUserRolesAction
{
    /**
     * @param  User  $user  The authenticated user to sync
     * @param  Collection  $newDisplayRoles  e.g. collect(['employer']) or collect(['expert'])
     */
    public function execute(User $user, Collection $newDisplayRoles): void
    {
        $currentRoles = $user->roles->pluck('name');

        // --- Employer ---
        if ($newDisplayRoles->contains('employer')) {
            if (! $currentRoles->contains('employer')) {
                $user->addRole('employer');
            }
            $user->employer()->firstOrCreate([EmployerProfile::USER_ID => $user->id]);
        } else {
            $user->removeRole('employer');
            $user->employer()->where(EmployerProfile::USER_ID, $user->id)->delete();
        }

        // --- Supplier — نقش خود-انتسابی نیست ---
        // انتخاب کاربر فقط پروفایل را (با وضعیت pending پیش‌فرض دیتابیس) می‌سازد.
        // نقش Laratrust روی قیمت‌گذاری (PricingRoleResolver) و فلگ‌های فرمول تسویه
        // اثر دارد، پس تنها AdminSupplierController::approve آن را متصل می‌کند.
        if ($newDisplayRoles->contains('supplier')) {
            $profile = $user->supplier()->firstOrCreate([SupplierProfile::USER_ID => $user->id]);

            // سوپلایر قبلاً تأییدشده که نقشش (مثلاً با تغییر نقش موقت) جدا شده،
            // بدون نیاز به تأیید مجدد ادمین نقش را پس می‌گیرد.
            if ($profile->isApproved() && ! $currentRoles->contains('supplier')) {
                $user->addRole('supplier');
            }
        } else {
            $user->removeRole('supplier');
            $user->supplier()->where(SupplierProfile::USER_ID, $user->id)->delete();
        }

        // --- Expert (engineer / contractor) — only clean-up on removal ---
        if (! $newDisplayRoles->contains('expert')) {
            $user->removeRoles(['engineer', 'contractor']);
            $user->engineer()->where(EngineerProfile::USER_ID, $user->id)->delete();
            $user->contractor()->where(ContractorProfile::USER_ID, $user->id)->delete();
            // مدارک تحصیلی و سوابق شغلی عمداً حذف نمی‌شوند: حذفشان برگشت‌ناپذیر بود
            // (رکوردهای تأییدشده + فایل‌های پیوست) و فایل‌های روی دیسک را orphan می‌کرد.
            // با تغییر نقش فقط غیرفعال می‌مانند و با انتخاب دوباره‌ی expert باز می‌گردند.
        }
    }
}
