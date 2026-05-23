<?php

namespace App\Http\Resources\Financial;

use App\Models\UserBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusText = match ($this->{UserBankAccount::STATUS}) {
            UserBankAccount::STATUS_PENDING => 'در انتظار تایید',
            UserBankAccount::STATUS_APPROVED => 'تایید شده',
            UserBankAccount::STATUS_REJECTED => 'ناموفق',
            default => $this->{UserBankAccount::STATUS},
        };

        return [
            UserBankAccount::ID => $this->id,
            UserBankAccount::BANK_NAME => $this->{UserBankAccount::BANK_NAME},
            UserBankAccount::ACCOUNT_HOLDER_NAME => $this->{UserBankAccount::ACCOUNT_HOLDER_NAME},
            UserBankAccount::SHABA => $this->{UserBankAccount::SHABA},
            UserBankAccount::CARD_NUMBER => $this->{UserBankAccount::CARD_NUMBER},
            'status' => $statusText,
            'status_code' => $this->{UserBankAccount::STATUS},
            UserBankAccount::REJECTION_REASON => $this->whenNotNull($this->{UserBankAccount::REJECTION_REASON}),
            UserBankAccount::IS_DEFAULT => $this->{UserBankAccount::IS_DEFAULT},
        ];
    }
}
