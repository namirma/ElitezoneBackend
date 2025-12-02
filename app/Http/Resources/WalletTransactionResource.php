<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ترجمه وضعیت‌ها و نوع‌ها برای نمایش به کاربر
        $statusText = match ($this->status) {
            WalletTransaction::STATUS_CONFIRMED => 'تایید شده',
            WalletTransaction::STATUS_PENDING => 'در انتظار تایید',
            WalletTransaction::STATUS_FAILED => 'ناموفق',
            default => $this->status,
        };

        $typeText = match ($this->type) {
            WalletTransaction::TYPE_DEPOSIT => 'افزایش موجودی',
            WalletTransaction::TYPE_WITHDRAW => 'برداشت از کیف پول',
            WalletTransaction::TYPE_FEE_HOLD => 'سهم رزرو شده',
            WalletTransaction::TYPE_FEE_RELEASE => 'آزادسازی سهم',
            default => $this->type,
        };

        $receiptInfo = $this->meta['receipt_info'] ?? [];
        return [
            'id'          => $this->id,
            'amount'      => (string) $this->amount,
            'type'        => $typeText,
            'status'      => $statusText,
            'description' => $this->description,
            'order_id'    => $this->order_id,
            'receipt_data' => [
                'number'      => $receiptInfo['receipt_number'] ?? null,
                'bank'        => $receiptInfo['bank_name'] ?? null,
                'date'        => $receiptInfo['payment_date'] ?? null,
                'image_url'   => isset($receiptInfo['image_path']) ? url('storage/' . $receiptInfo['image_path']) : null,
            ],
            'created_at'  => $this->created_at?->format('Y-m-d H:i:s'),
            'created_at_p' => $this->created_at ? verta($this->created_at)->format('Y/m/d') : null,
        ];
    }
}
