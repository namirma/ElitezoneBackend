<?php

namespace App\Http\Resources\Financial;

use App\Enums\Shared\StatusTone;
use App\Models\UserBankAccount;
use App\Http\Resources\BaseJsonResource;
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
            UserBankAccount::STATUS_PENDING  => 'در انتظار تایید',
            UserBankAccount::STATUS_APPROVED => 'تایید شده',
            UserBankAccount::STATUS_REJECTED => 'رد شده',
            default                          => $statusKey,
        };

        return [
            UserBankAccount::ID                   => $this->id,
            UserBankAccount::BANK_NAME            => $this->{UserBankAccount::BANK_NAME},
            UserBankAccount::ACCOUNT_HOLDER_NAME  => $this->{UserBankAccount::ACCOUNT_HOLDER_NAME},
            UserBankAccount::SHABA                => $this->{UserBankAccount::SHABA},
            UserBankAccount::CARD_NUMBER          => $this->{UserBankAccount::CARD_NUMBER},

            // status_info — ساختار یکپارچه
            'status_info' => [
                'key'   => $statusKey,
                'label' => $statusLabel,
                'tone'  => StatusTone::fromStatus($statusKey)->value,
            ],
            // حفظ فیلدهای قدیمی برای سازگاری عقبگرد
            'status'      => $statusLabel,
            'status_code' => $statusKey,

            UserBankAccount::REJECTION_REASON => $this->whenNotNull($this->{UserBankAccount::REJECTION_REASON}),
            UserBankAccount::IS_DEFAULT       => $this->{UserBankAccount::IS_DEFAULT},
        ];
    }
}
