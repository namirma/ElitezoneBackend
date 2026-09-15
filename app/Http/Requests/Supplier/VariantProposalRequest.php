<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Http\Requests\Product\ProductSpecRules;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * پیشنهادِ ساخت یا ویرایشِ یک واریانت — همان فیلدهای فرمِ ادمین منهای
 * `is_active` و `stock_quantity` (دلیلش در `ProductProposalRequest`).
 */
class VariantProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** هدفِ ویرایش — این کلاس درخواستِ ساخت است (روتش `{variant}` ندارد)؛ زیرکلاسِ Update آن را از روت می‌خواند. */
    protected function targetVariant(): ?ProductVariant
    {
        return null;
    }

    public function isUpdate(): bool
    {
        return $this->targetVariant() instanceof ProductVariant;
    }

    public function rules(): array
    {
        $variant = $this->targetVariant();
        $isUpdate = $variant instanceof ProductVariant;

        $rules = Arr::except(
            ProductSpecRules::variant($isUpdate ? $variant->id : null, partial: $isUpdate),
            ProductProposalRequest::ADMIN_ONLY_VARIANT,
        );

        $rules += ProductSpecRules::attributes('attributes', partial: true);
        $rules['note'] = ['nullable', 'string', 'max:1000'];

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'attributes.*.attribute_id' => __('product_changes.fields.attribute'),
            'attributes.*.attribute_value_id' => __('product_changes.fields.attribute_value'),
        ];
    }
}
