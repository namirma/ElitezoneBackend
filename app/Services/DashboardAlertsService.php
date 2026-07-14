<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;

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
        $orders = Order::where(function ($q) use ($user) {
            $q->where('placer_user_id', $user->id)->orWhere('beneficiary_user_id', $user->id);
        })
            ->whereIn('status', ['pending', 'reserved_extended', 'weighbridge_done', 'delivered'])
            ->latest()
            ->get();

        foreach ($orders as $order) {
            if (in_array($order->status, ['pending', 'reserved_extended']) && $order->payment_status === 'pending') {
                $alerts[] = $this->makeAlert('order_pay', 'error', 'payment', "/profile/orders/{$order->id}", ['order_number' => $order->order_number], $order->id);
            }
            if ($order->status === 'weighbridge_done' && $order->final_invoice_ref) {
                $alerts[] = $this->makeAlert('order_invoice', 'info', 'document-text', "/profile/orders/{$order->id}", ['order_number' => $order->order_number], $order->id);
            }
            if ($order->status === 'delivered' && $order->survey_status !== 'completed') {
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
            $alerts[] = $this->makeAlert('wallet_deposit', 'success', 'wallet', '/profile/wallet', ['amount' => number_format($deposit->amount)], $deposit->id);
        }

        return $alerts;
    }

    private function checkInvitationAlerts(User $user): array
    {
        $alerts = [];
        foreach ($user->associatedEmployers()->wherePivot('status', 'pending')->get() as $employer) {
            $alerts[] = $this->makeAlert('invite_received', 'info', 'user-group', '/profile/consultants', ['name' => $employer->profile->name], $employer->id);
        }
        foreach ($user->technicalEliteYars()->wherePivot('status', 'rejected')->where('employer_consultant.updated_at', '>=', now()->subDays(3))->get() as $consultant) {
            $alerts[] = $this->makeAlert('invite_rejected', 'warning', 'user-minus', '/profile/consultants', ['name' => $consultant->profile->name], $consultant->id);
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
            $alerts[] = $this->makeAlert('wishlist_stock', 'success', 'bell', '/products/'.$item->product->slug, ['name' => $item->name], $item->id);
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
