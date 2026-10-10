<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;

/**
 * Collects contextual alert notifications for the user's dashboard.
 *
 * Each check method returns an array of alert structures; the controller
 * only calls collectAlerts() and never needs to know the details.
 */
class DashboardAlertsService
{
    /**
     * Return all alerts applicable to the given user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectAlerts(User $user): array
    {
        return array_merge(
            $this->checkProfileAlerts($user),
            $this->checkOrderAlerts($user),
            $this->checkDocumentAlerts($user),
            $this->checkWalletAlerts($user),
            $this->checkInvitationAlerts($user),
            $this->checkWishlistAlerts($user)
        );
    }

    // -------------------------------------------------------------------------
    // Individual alert checks
    // -------------------------------------------------------------------------

    private function checkProfileAlerts(User $user): array
    {
        $alerts = [];
        $profile = $user->profile;

        if ($profile && $profile->entity === 'legal' && empty($profile->economic_code)) {
            $alerts[] = $this->makeAlert('profile_incomplete_economic', 'warning', 'user-edit', '/profile/account_information/legal');
        }

        if ($profile && empty($profile->email_verified_at) && ! empty($profile->email)) {
            $alerts[] = $this->makeAlert('email_verify', 'warning', 'mail', '/profile/account_settings');
        }

        if (! $user->bankAccounts()->where('status', 'approved')->exists()) {
            $alerts[] = $this->makeAlert('no_bank_account', 'info', 'credit-card', '/profile/finance/accounts_number');
        }

        return $alerts;
    }

    private function checkOrderAlerts(User $user): array
    {
        $alerts = [];
        // ⚠️ این متد تنها جای پروژه بود که وضعیت سفارش را با **رشته‌ی خام** می‌خواند
        // و به همین دلیل از جاروی حذفِ وضعیت‌ها (فاز ۰) جا ماند: `reserved_extended`
        // و `weighbridge_done` دیگر وجود ندارند، پس هشدارِ `order_invoice` برای همیشه
        // مرده بود و `order_pay` بی‌صدا فقط به `pending` محدود شده بود.
        // از این به بعد `Order::STATUS_*` — تا حذفِ بعدی این فایل را هم بشکند.
        $orders = Order::where(function ($q) use ($user) {
            $q->where(Order::PLACER_USER_ID, $user->id)->orWhere(Order::BENEFICIARY_USER_ID, $user->id);
        })
            ->whereIn(Order::STATUS, [
                Order::STATUS_RESERVED,
                Order::STATUS_SHIPPED,
                Order::STATUS_PARTIALLY_DELIVERED,
                Order::STATUS_DELIVERED,
            ])
            ->latest()
            ->get();

        foreach ($orders as $order) {
            if (
                $order->status === Order::STATUS_RESERVED
                && $order->payment_status === Order::PAYMENT_PENDING
            ) {
                $alerts[] = $this->makeAlert('order_pay', 'error', 'payment', "/profile/orders/{$order->id}", ['order_number' => $order->order_number], $order->id);
            }
            // «فاکتور نهایی آماده است» — پس از باسکول، که حالا با ثبت بارنامه رخ
            // می‌دهد و سفارش را به `shipped` می‌برد.
            if (
                in_array($order->status, [
                    Order::STATUS_SHIPPED,
                    Order::STATUS_PARTIALLY_DELIVERED,
                    Order::STATUS_DELIVERED,
                ], true)
                && $order->final_invoice_ref
            ) {
                $alerts[] = $this->makeAlert('order_invoice', 'info', 'document-text', "/profile/orders/{$order->id}", ['order_number' => $order->order_number], $order->id);
            }
            // نظرسنجی را مدیریت ۱۴۰۵/۰۶/۲۲ رسماً بست (پاسخِ ۱-۶) و روتِ ثبتش پشتِ همین فلگ است؛
            // بدونِ این شرط هشدار برای هر سفارشِ تحویل‌شده ساخته می‌شد و هرگز پاک نمی‌شد.
            if (config('features.order_surveys') && $order->status === Order::STATUS_DELIVERED && $order->survey_status !== 'completed') {
                $alerts[] = $this->makeAlert('order_survey', 'success', 'star', "/profile/orders/{$order->id}", ['order_number' => $order->order_number], $order->id);
            }
        }

        return $alerts;
    }

    private function checkDocumentAlerts(User $user): array
    {
        $alerts = [];
        foreach ($user->jobExperiences()->where('status', 'rejected')->get() as $job) {
            $alerts[] = $this->makeAlert('job_rejected', 'error', 'briefcase', '/profile/professional/job', ['reason' => $job->rejection_reason ?? '-'], $job->id);
        }

        $rejectedEdu = $user->eduixRecords()->where('admin_status', 'rejected')->first();
        if ($rejectedEdu) {
            $alerts[] = $this->makeAlert('edu_rejected', 'error', 'academic-cap', '/profile/professional/education', ['reason' => $rejectedEdu->rejection_reason ?? '-'], $rejectedEdu->id);
        }

        if ($user->hasRole(['engineer', 'contractor']) && $user->jobExperiences()->count() === 0) {
            $alerts[] = $this->makeAlert('job_empty', 'info', 'briefcase-add', '/profile/professional/job');
        }

        return $alerts;
    }

    private function checkWalletAlerts(User $user): array
    {
        $alerts = [];
        $deposit = $user->wallet?->transactions()
            ->where('type', 'deposit')->where('status', 'confirmed')
            ->where('created_at', '>=', now()->subDay())->latest()->first();

        if ($deposit) {
            $alerts[] = $this->makeAlert('wallet_deposit', 'success', 'wallet', '/profile/finance/wallet', ['amount' => Money::format($deposit->amount)], $deposit->id);
        }

        return $alerts;
    }

    private function checkInvitationAlerts(User $user): array
    {
        $alerts = [];
        foreach ($user->associatedEmployers()->wherePivot('status', 'pending')->get() as $employer) {
            $alerts[] = $this->makeAlert('invite_received', 'info', 'user-group', '/profile/employers', ['name' => $employer->profile->name], $employer->id);
        }
        foreach ($user->technicalEliteYARs()->wherePivot('status', 'rejected')->where('employer_elite_yar.updated_at', '>=', now()->subDays(3))->get() as $eliteYAR) {
            $alerts[] = $this->makeAlert('invite_rejected', 'warning', 'user-minus', '/profile/employers', ['name' => $eliteYAR->profile->name], $eliteYAR->id);
        }

        return $alerts;
    }

    private function checkWishlistAlerts(User $user): array
    {
        $alerts = [];
        $items = ProductVariant::whereHas('wishlists', fn ($q) => $q->where('user_id', $user->id))
            ->where('stock_quantity', '>', 0)
            ->where('updated_at', '>=', now()->subDay())
            ->take(3)->get();

        foreach ($items as $item) {
            // روتِ جزئیات محصول در SPA **مفرد** است و **اول id** می‌آید:
            // `/product/{id}/{slug?}`. شکلِ قبلی (`/products/{slug}`) هیچ‌وقت
            // وجود نداشت و مستقیم به ۴۰۴ می‌رفت.
            $alerts[] = $this->makeAlert('wishlist_stock', 'success', 'bell', '/product/'.$item->product->id.'/'.$item->product->slug, ['name' => $item->name], $item->id);
        }

        return $alerts;
    }

    // -------------------------------------------------------------------------
    // Alert builder helper
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function makeAlert(string $key, string $type, string $icon, string $link, array $params = [], mixed $idSuffix = null): array
    {
        return [
            'id' => $key.($idSuffix !== null ? '_'.$idSuffix : ''),
            'type' => $type,
            'icon' => $icon,
            'title' => __('dashboard.alerts.'.$key.'.title', $params),
            'body' => __('dashboard.alerts.'.$key.'.body', $params),
            'cta_text' => __('dashboard.alerts.'.$key.'.cta'),
            'cta_link' => $link,
        ];
    }
}
