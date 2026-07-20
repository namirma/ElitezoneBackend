<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * پنل مدیریت — خروجی CSV گزارش‌ها (ماژول ۶ نیازمندی فرانت BackOffice).
 *
 * فقط CSV (JSON از data/analytics/dashboard موجود است). خروجی UTF-8 با BOM
 * تا اکسل فارسی را درست نمایش دهد؛ استریم cursor()ای تا حافظه منفجر نشود.
 * فیلتر اختیاری بازه تاریخ (میلادی): ?from=Y-m-d&to=Y-m-d
 */
class AdminReportController extends Controller
{
    // GET /admin/reports/orders?format=csv
    public function orders(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);

        $query = Order::query()
            ->with('placer.profile')
            ->when($from, fn ($q) => $q->whereDate(Order::CREATED_AT, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate(Order::CREATED_AT, '<=', $to))
            ->orderBy(Order::ID);

        return $this->streamCsv('orders-report.csv',
            ['ID', 'Status', 'Payment Status', 'Buyer Mobile', 'Buyer Name', 'Final Amount (Rials)', 'Created At'],
            function ($writeRow) use ($query) {
                foreach ($query->cursor() as $order) {
                    $profile = $order->placer?->profile;
                    $writeRow([
                        $order->id,
                        $order->status,
                        $order->payment_status,
                        $order->placer?->mobile,
                        trim(($profile?->name ?? '').' '.($profile?->family ?? '')),
                        $order->final_amount,
                        (string) $order->{Order::CREATED_AT},
                    ]);
                }
            });
    }

    // GET /admin/reports/financial?format=csv
    public function financial(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);

        $query = WalletTransaction::query()
            ->with('wallet.user.profile')
            ->when($from, fn ($q) => $q->whereDate(WalletTransaction::CREATED_AT, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate(WalletTransaction::CREATED_AT, '<=', $to))
            ->orderBy(WalletTransaction::ID);

        return $this->streamCsv('financial-report.csv',
            ['ID', 'User Mobile', 'Type', 'Amount (Rials)', 'Status', 'Order ID', 'Description', 'Created At'],
            function ($writeRow) use ($query) {
                foreach ($query->cursor() as $tx) {
                    $writeRow([
                        $tx->id,
                        $tx->wallet?->user?->mobile,
                        $tx->type,
                        $tx->amount,
                        $tx->status,
                        $tx->order_id,
                        $tx->description,
                        (string) $tx->{WalletTransaction::CREATED_AT},
                    ]);
                }
            });
    }

    // GET /admin/reports/users?format=csv
    public function users(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);

        $query = User::query()
            ->with(['profile', 'roles'])
            ->when($from, fn ($q) => $q->whereDate(User::CREATED_AT, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate(User::CREATED_AT, '<=', $to))
            ->orderBy(User::ID);

        return $this->streamCsv('users-report.csv',
            ['ID', 'Mobile', 'Name', 'Email', 'Roles', 'Active', 'Registered At'],
            function ($writeRow) use ($query) {
                foreach ($query->cursor() as $user) {
                    $writeRow([
                        $user->id,
                        $user->mobile,
                        trim(($user->profile?->name ?? '').' '.($user->profile?->family ?? '')),
                        $user->profile?->email,
                        $user->roles->pluck('name')->implode('|'),
                        $user->{User::IS_ACTIVE} === false ? '0' : '1',
                        (string) $user->{User::CREATED_AT},
                    ]);
                }
            });
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [$validated['from'] ?? null, $validated['to'] ?? null];
    }

    /**
     * @param  list<string>  $headers
     * @param  callable(callable(array<int, mixed>): void): void  $rows
     */
    private function streamCsv(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // BOM تا Excel خروجی UTF-8 فارسی را درست بخواند
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            $rows(function (array $row) use ($out) {
                fputcsv($out, $row);
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
