<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FinalizeOrderInvoiceRequest extends FormRequest
{
    /**
     * Defense-in-depth authorization — delegates to OrderPolicy::finalizeInvoice
     * (placer / beneficiary / admin via before()). The controller also calls
     * $this->authorize('finalizeInvoice', $order), so a failure here would be
     * caught at the policy layer too — but we want to reject early.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        if (! $order instanceof Order) {
            return false;
        }

        return $this->user()?->can('finalizeInvoice', $order) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'final_invoice_ref' => ['required', 'string', 'max:255'],
        ];
    }
}
