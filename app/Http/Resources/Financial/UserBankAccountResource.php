<?php

namespace App\Http\Resources\Financial;

use App\Enums\Shared\StatusTone;
use App\Http\Resources\BaseJsonResource;
use App\Models\UserBankAccount;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\UserBankAccount
 */
class UserBankAccountResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $statusKey = $this->{UserBankAccount::STATUS};

        $statusLabel = match ($statusKey) {
            UserBankAccount::STATUS_PENDING => 'در انتظار تایید',
            UserBankAccount::STATUS_APPROVED => 'تایید شده',
            UserBankAccount::STATUS_REJECTED => 'رد شده',
            default => $statusKey,
        };

        return [
            UserBankAccount::ID => $this->id,
            UserBankAccount::BANK_NAME => $this->{UserBankAccount::BANK_NAME},
            UserBankAccount::ACCOUNT_HOLDER_NAME => $this->{UserBankAccount::ACCOUNT_HOLDER_NAME},
            // ادمین برای تسویه به مقدار کامل نیاز دارد؛ برای بقیه mask می‌شود
            UserBankAccount::SHABA => $request->user()?->hasRole('admin')
                ? $this->{UserBankAccount::SHABA}
                : $this->maskShaba($this->{UserBankAccount::SHABA}),
            UserBankAccount::CARD_NUMBER => $request->user()?->hasRole('admin')
                ? $this->{UserBankAccount::CARD_NUMBER}
                : $this->maskCardNumber($this->{UserBankAccount::CARD_NUMBER}),

            // status_info — ساختار یکپارچه
            'status_info' => [
                'key' => $statusKey,
                'label' => $statusLabel,
                'tone' => StatusTone::fromStatus($statusKey)->value,
            ],
            // حفظ فیلدهای قدیمی برای سازگاری عقبگرد
            'status' => $statusLabel,
            'status_code' => $statusKey,

            UserBankAccount::REJECTION_REASON => $this->whenNotNull($this->{UserBankAccount::REJECTION_REASON}),
            UserBankAccount::IS_DEFAULT => $this->{UserBankAccount::IS_DEFAULT},
        ];
    }

    /**
     * شبا و شماره کارت داده‌ی بانکی حساس‌اند — هرگز کامل در پاسخ API برنمی‌گردند.
     * کاربر برای ویرایش، مقدار جدید را از ابتدا وارد می‌کند.
     */
    private function maskShaba(?string $shaba): ?string
    {
        if ($shaba === null || strlen($shaba) < 8) {
            return $shaba;
        }

        return substr($shaba, 0, 4).str_repeat('*', strlen($shaba) - 8).substr($shaba, -4);
    }

    private function maskCardNumber(?string $card): ?string
    {
        if ($card === null || strlen($card) < 10) {
            return $card;
        }

        return substr($card, 0, 6).str_repeat('*', strlen($card) - 10).substr($card, -4);
    }
}
