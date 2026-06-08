<?php

namespace App\Http\Resources\Financial;

use App\Enums\Shared\StatusTone;
use App\Models\WalletTransaction;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\WalletTransaction
 */
class WalletTransactionResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $statusKey = $this->status;

        $statusLabel = match ($statusKey) {
            WalletTransaction::STATUS_CONFIRMED => 'تایید شده',
            WalletTransaction::STATUS_PENDING   => 'در انتظار تایید',
            WalletTransaction::STATUS_FAILED    => 'ناموفق',
            WalletTransaction::STATUS_REJECTED  => 'رد شده',
            'hold'                              => 'رزرو شده (امانی)',
            default                             => $statusKey,
        };

        $typeText = match ($this->type) {
            WalletTransaction::TYPE_DEPOSIT     => 'افزایش موجودی',
            WalletTransaction::TYPE_WITHDRAW    => 'برداشت از کیف پول',
            WalletTransaction::TYPE_FEE_HOLD    => 'سهم رزرو شده',
            WalletTransaction::TYPE_FEE_RELEASE => 'آزادسازی سهم',
            default                             => $this->type,
        };

        $meta = $this->meta ?? [];
        $receiptInfo = $meta['receipt_info'] ?? [];
        $filePath = $meta['receipt_path'] ?? ($receiptInfo['image_path'] ?? null);
        $displayFileName = $receiptInfo['original_name'] ?? ($filePath ? basename($filePath) : null);
        $downloadLink = $filePath
            ? route('api.financial.download', ['transaction' => $this->id])
            : null;

        return [
            'id'     => $this->id,
            'amount' => (string) $this->amount,
            'type'   => $typeText,

            // status_info — ساختار یکپارچه
            'status_info' => [
                'key'   => $statusKey,
                'label' => $statusLabel,
                'tone'  => StatusTone::fromStatus($statusKey)->value,
            ],
            // حفظ فیلد قدیمی برای سازگاری عقبگرد
            'status' => $statusLabel,

            'description' => $this->description,
            'order_id'    => $this->order_id,
            'receipt_data' => [
                'number'        => $receiptInfo['receipt_number'] ?? null,
                'bank'          => $receiptInfo['bank_name'] ?? null,
                'date'          => $receiptInfo['payment_date'] ?? null,
                'file_name'     => $displayFileName,
                'download_link' => $downloadLink,
                'image_url'     => $displayFileName,
            ],
            'created_at'   => $this->created_at?->format('Y-m-d H:i:s'),
            'created_at_p' => $this->created_at ? verta($this->created_at)->format('Y/m/d') : null,
        ];
    }
}
