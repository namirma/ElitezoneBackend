<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Jobs\Notification\SendSmsJob;
use App\Models\Agreement;
use App\Models\EmployerEliteYAR;
use App\Models\EmployerProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * «حسابِ مجازیِ کارفرما» — مدیریت، دورِ هفتم، پرسشِ ۷-۵ (۱۴۰۵/۰۷/۱۵)، تنها جایی که این قاعده‌ها زندگی می‌کنند:
 *
 * - باز می‌شود وقتی الیت‌یارِ **تأییدشده** کارفرمایی را که حساب ندارد با «تأییدِ ذی‌نفع» ثبت می‌کند (پودیوم: کدِ ملی↔موبایل
 *   و کدِ ملی↔تاریخِ تولد). ردیفِ کاربر همان‌جا ساخته می‌شود؛ **پیش از رضایتِ کارفرما هیچ چیزی به آن داده نمی‌شود**.
 * - با کدِ پیامکی‌ای که خودِ کارفرما به الیت‌یار می‌دهد (رضایت)، حساب نقشِ کارفرما، پروفایلِ کارفرما و رابطه‌ی **تأییدشده**
 *   با همان الیت‌یار می‌گیرد — «در این حساب الیت یاری که کار خرید را پیش برده به عنوان الیت یار تایید شده ثبت شده است».
 * - پس از پرداخت، پیامکِ «لینکِ تأییدِ حساب و ورود» (امضاشده، با انقضا) می‌رود؛ ورود با آن مرحله‌ی هویت و نقش ندارد.
 * - «کارفرما تنها باید توافقنامه ها را بپذیرد و ثبت نام پایان می یابد» — با پذیرشِ آخرین توافق‌نامه‌ی لازم، حساب واقعی
 *   می‌شود و لینک دیگر کار نمی‌کند.
 *
 * حسابِ واقعیِ موجود (کسی که خودش ثبت‌نام کرده) هرگز تصاحب نمی‌شود: نه نقش، نه رابطه، نه بازنویسیِ هویت.
 */
final class VirtualEmployerAccount
{
    public const CLAIM_ROUTE = 'auth.virtual-account.claim';

    private const LOG = 'virtual_account';

    /** آیا این خریدار می‌تواند برای کارفرمای بی‌حساب حسابِ مجازی باز کند؟ */
    public function canOpenFor(User $buyer): bool
    {
        return (bool) config('features.virtual_employer_accounts') && $buyer->isVerifiedEliteYAR();
    }

    /** ردیفی که همین حالا با «تأییدِ ذی‌نفع» ساخته شد — هنوز بی‌نقش و بی‌رابطه، تا رضایتِ کارفرما. */
    public function markCreatedBy(User $employer, User $eliteYar): void
    {
        if ($employer->{User::CREATED_BY_ELITE_YAR_ID} === null) {
            $employer->forceFill([User::CREATED_BY_ELITE_YAR_ID => $eliteYar->id])->save();
        }
    }

    /**
     * کارفرما کدِ پیامکی را داد. فقط روی حسابِ مجازی (هنوز تحویل‌گرفته‌نشده) اثر دارد؛ حسابِ واقعی همان مسیرِ دعوت و
     * پذیرشِ خودش را دارد. الیت‌یارِ دوم هم که همین کارفرما را تأیید کند، رابطه‌ی تأییدشده‌ی خودش را می‌گیرد.
     */
    public function establish(User $employer, User $eliteYar): void
    {
        if (! $employer->isVirtualAccount() || ! $this->canOpenFor($eliteYar)) {
            return;
        }

        DB::transaction(function () use ($employer, $eliteYar) {
            if (! $employer->hasRole('employer')) {
                $employer->addRole('employer');
            }
            $employer->employer()->firstOrCreate([EmployerProfile::USER_ID => $employer->id]);

            $relation = EmployerEliteYAR::query()
                ->where(EmployerEliteYAR::EMPLOYER_ID, $employer->id)
                ->where(EmployerEliteYAR::ELITE_YAR_ID, $eliteYar->id)
                ->first();

            if ($relation === null) {
                $employer->technicalEliteYARs()->attach($eliteYar->id, [EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_APPROVED]);
            } elseif ($relation->getAttribute(EmployerEliteYAR::STATUS) !== EmployerEliteYAR::STATUS_APPROVED) {
                $employer->technicalEliteYARs()->updateExistingPivot($eliteYar->id, [EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_APPROVED]);
            }

            activity(self::LOG)
                ->performedOn($employer)
                ->causedBy($eliteYar)
                ->withProperties(['elite_yar_id' => $eliteYar->id, 'created_by_elite_yar_id' => $employer->{User::CREATED_BY_ELITE_YAR_ID}])
                ->log('حسابِ مجازیِ کارفرما با تأییدِ ذی‌نفع فعال شد؛ الیت‌یار به‌عنوانِ الیت‌یارِ تأییدشده‌ی کارفرما ثبت شد.');
        });
    }

    /**
     * لینکِ صفحه‌ی «تأییدِ حساب و ورود» در سایت. امضا **نسبی** است (`signed:relative`) تا میزبانِ پشتِ پروکسی امضا را
     * نشکند. خالی‌بودنِ `auth.virtual_account.claim_url` یعنی آن صفحه هنوز نیست — null، و پیامکی هم نمی‌رود.
     */
    public function claimLink(User $employer): ?string
    {
        $page = rtrim((string) config('auth.virtual_account.claim_url'), '/');

        if ($page === '' || ! $employer->isVirtualAccount()) {
            return null;
        }

        $signed = URL::temporarySignedRoute(
            self::CLAIM_ROUTE,
            now()->addDays(max(1, (int) config('auth.virtual_account.claim_link_days', 7))),
            ['user' => $employer->id],
            absolute: false,
        );
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return $page.'?'.http_build_query(['u' => $employer->id] + $query);
    }

    /** پس از پرداخت — «به او اطلاع می‌دهد که می‌تواند روند سفارش و موارد مالی را از روی وبسایت دنبال کند». */
    public function sendClaimLink(User $payer, Order $order): void
    {
        $link = $this->claimLink($payer);

        if ($link === null) {
            return;
        }

        // فقط پیامک — لینکِ ورود اعتبارنامه است و نباید در اعلانِ ذخیره‌شده (`user_notifications`) بماند.
        SendSmsJob::template((string) $payer->{User::MOBILE}, 'employer_account_claim', [
            'order_number' => (string) $order->{Order::ORDER_NUMBER},
            'link' => $link,
        ]);
    }

    /** با پذیرشِ هر توافق‌نامه صدا زده می‌شود؛ وقتی چیزی باقی نماند، ثبت‌نام تمام است. */
    public function claimIfRegistrationComplete(User $user): void
    {
        if (! $user->isVirtualAccount()) {
            return;
        }

        foreach (Agreement::relevantSlugsFor($user) as $slug) {
            if (! $user->hasAcceptedLatestAgreement($slug)) {
                return;
            }
        }

        $user->forceFill([User::ACCOUNT_CLAIMED_AT => now()])->save();

        activity(self::LOG)
            ->performedOn($user)
            ->causedBy($user)
            ->log('کارفرما توافق‌نامه‌ها را پذیرفت؛ حسابِ مجازی واقعی شد.');
    }
}
