<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * منبع واحد کوئری/ستون‌های گزارش‌های CSV ادمین.
 * هم استریم مستقیم (AdminReportController) و هم تولید در صف
 * (GenerateCsvReportJob) از همین کلاس استفاده می‌کنند تا خروجی یکسان بماند.
 */
class CsvReportBuilder
{
    public const TYPES = ['orders', 'financial', 'users'];

    public function query(string $type, ?string $from, ?string $to): Builder
    {
        $query = match ($type) {
            'orders' => Order::query()->with('placer.profile')->orderBy(Order::ID),
            'financial' => WalletTransaction::query()->with('wallet.user.profile')->orderBy(WalletTransaction::ID),
            'users' => User::query()->with(['profile', 'roles'])->orderBy(User::ID),
            default => throw new InvalidArgumentException("Unknown report type [{$type}]"),
        };

        $dateColumn = 'created_at';

        return $query
            ->when($from, fn ($q) => $q->whereDate($dateColumn, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($dateColumn, '<=', $to));
    }

    /**
     * @return list<string>
     */
    public function headers(string $type): array
    {
        return match ($type) {
            'orders' => ['ID', 'Status', 'Payment Status', 'Buyer Mobile', 'Buyer Name', 'Final Amount (Rials)', 'Created At'],
            'financial' => ['ID', 'User Mobile', 'Type', 'Amount (Rials)', 'Status', 'Order ID', 'Description', 'Created At'],
            'users' => ['ID', 'Mobile', 'Name', 'Email', 'Roles', 'Active', 'Registered At'],
            default => throw new InvalidArgumentException("Unknown report type [{$type}]"),
        };
    }

    /**
     * @return array<int, mixed>
     */
    public function row(string $type, object $model): array
    {
        return match ($type) {
            'orders' => [
                $model->id,
                $model->status,
                $model->payment_status,
                $model->placer?->mobile,
                trim(($model->placer?->profile?->name ?? '').' '.($model->placer?->profile?->family ?? '')),
                $model->final_amount,
                (string) $model->created_at,
            ],
            'financial' => [
                $model->id,
                $model->wallet?->user?->mobile,
                $model->type,
                $model->amount,
                $model->status,
                $model->order_id,
                $model->description,
                (string) $model->created_at,
            ],
            'users' => [
                $model->id,
                $model->mobile,
                trim(($model->profile?->name ?? '').' '.($model->profile?->family ?? '')),
                $model->profile?->email,
                $model->roles->pluck('name')->implode('|'),
                $model->is_active === false ? '0' : '1',
                (string) $model->created_at,
            ],
            default => throw new InvalidArgumentException("Unknown report type [{$type}]"),
        };
    }

    /**
     * نوشتن کامل گزارش در یک stream باز (با BOM برای اکسل فارسی).
     *
     * @param  resource  $stream
     */
    public function writeTo($stream, string $type, ?string $from, ?string $to): int
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $this->headers($type));

        $rows = 0;
        foreach ($this->query($type, $from, $to)->cursor() as $model) {
            fputcsv($stream, $this->row($type, $model));
            $rows++;
        }

        return $rows;
    }
}
