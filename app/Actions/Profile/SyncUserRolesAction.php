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
     * @param  Collection  $newDisplayRoles  e.g. collect(['employer']) or collect(['elite_yar'])
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
            $this->dropRole($user, $currentRoles, 'employer');
            $user->employer()->where(EmployerProfile::USER_ID, $user->id)->delete();
        }

        // --- Supplier — نقش خود-انتسابی نیست ---
        // انتخاب کاربر فقط پروفایل را (با وضعیت pending پیش‌فرض دیتابیس) می‌سازد.
        // نقش Laratrust روی قیمت‌گذاری (PricingRoleResolver) و فلگ‌های فرمول تسویه
        // اثر دارد، پس تنها AdminSupplierController::approve آن را متصل می‌کند.
        if ($newDisplayRoles->contains('supplier')) {
            $profile = $user->supplier()->firstOrCreate([SupplierProfile::USER_ID => $user->id]);

            // انتخابِ نقش فقط یک پیش‌نویس می‌سازد؛ تا متقاضی فرمِ درخواست را
            // ارسال نکند (`SupplierController::store`) در صفِ ادمین دیده نمی‌شود.
            // پیش‌فرضِ ستون `pending` مانده تا پروفایل‌های ساخته‌شده در تست‌ها و
            // داده‌های قدیمی همان معنا را داشته باشند.
            if ($profile->wasRecentlyCreated) {
                $profile->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_DRAFT])->save();
            }

            // سوپلایر قبلاً تأییدشده که نقشش (مثلاً با تغییر نقش موقت) جدا شده،
            // بدون نیاز به تأیید مجدد ادمین نقش را پس می‌گیرد.
            if ($profile->isApproved() && ! $currentRoles->contains('supplier')) {
                $user->addRole('supplier');
            }
        } else {
            $this->dropRole($user, $currentRoles, 'supplier');
            $user->supplier()->where(SupplierProfile::USER_ID, $user->id)->delete();
        }

        // --- Elite yar ---
        //
        // `elite_yar` نقشِ **قصد** است و همین‌جا وصل می‌شود؛ `engineer`/`contractor`
        // نقشِ **مدرک**‌اند و فقط بعد از تأیید مدارک توسط ادمین وصل می‌شوند
        // (EngineerController / ContractorController). جدا نگه‌داشتنشان همان چیزی
        // است که به گاردها اجازه می‌دهد یک سیگنال بخوانند: قبلاً الیت یارِ تازه هیچ
        // نقشی نداشت و هر گارد مجبور بود `type.slug` را هم چک کند.
        if ($newDisplayRoles->contains('elite_yar')) {
            if (! $currentRoles->contains('elite_yar')) {
                $user->addRole('elite_yar');
            }
        } else {
            $this->dropRole($user, $currentRoles, 'elite_yar');
            $this->dropRole($user, $currentRoles, 'engineer');
            $this->dropRole($user, $currentRoles, 'contractor');
            $user->engineer()->where(EngineerProfile::USER_ID, $user->id)->delete();
            $user->contractor()->where(ContractorProfile::USER_ID, $user->id)->delete();
            // مدارک تحصیلی و سوابق شغلی عمداً حذف نمی‌شوند: حذفشان برگشت‌ناپذیر بود
            // (رکوردهای تأییدشده + فایل‌های پیوست) و فایل‌های روی دیسک را orphan می‌کرد.
            // با تغییر نقش فقط غیرفعال می‌مانند و با انتخاب دوباره‌ی elite_yar باز می‌گردند.
        }
    }

    /**
     * نقش را فقط وقتی جدا می‌کند که کاربر واقعاً آن را داشته باشد.
     *
     * `removeRole()`/`removeRoles()` نامِ نقش را به مدل resolve می‌کنند و اگر
     * ردیفِ نقش در جدول نباشد `ModelNotFoundException` می‌اندازند — که هندلر به
     * ۴۰۴ با پیامِ «مسیر یا آیتم مورد نظر یافت نشد» تبدیلش می‌کند.
     *
     * نتیجه این بود که انتخابِ «کارفرما» شکست می‌خورد چون این اکشن در پایان
     * `removeRoles(['engineer','contractor'])` را صدا می‌زند — نقش‌هایی که کاربر
     * هرگز نداشته. یعنی یک درخواستِ کاملاً درست، به‌خاطر نبودِ ردیفی که اصلاً
     * لازمش نداشت، ۴۰۴ می‌گرفت. «حذفِ چیزی که نداری» باید no-op باشد نه خطا.
     *
     * @param  Collection<int,string>  $currentRoles
     */
    private function dropRole(User $user, Collection $currentRoles, string $role): void
    {
        if ($currentRoles->contains($role)) {
            $user->removeRole($role);
        }
    }
}
