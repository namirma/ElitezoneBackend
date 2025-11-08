<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletDepositRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array {
        return [
            'amount' => ['required', 'integer', 'min:10000', 'max:1000000000'], // حداقل و حداکثر مبلغ واریز
        ];
    }
}
