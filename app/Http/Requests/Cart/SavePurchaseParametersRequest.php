<?php

namespace App\Http\Requests\Cart;

use App\Models\PurchaseParameterValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SavePurchaseParametersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parameters' => ['required', 'array', 'min:2'],
            'parameters.*' => ['integer', 'exists:purchase_parameter_values,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'parameters.required' => 'انتخاب پارامترها الزامی است.',
            'parameters.min' => 'حداقل باید پارامترهای نوع مشتری و وضعیت الیت یار انتخاب شوند.',
            'parameters.*.exists' => 'یکی از پارامترهای انتخاب شده معتبر نیست.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $parameterIds = $this->input('parameters', []);

            $valuesCollection = PurchaseParameterValue::with('group')
                ->whereIn('id', $parameterIds)
                ->get();

            $groupedValues = $valuesCollection->groupBy('group_id');
            $duplicateGroup = $groupedValues->first(fn ($group) => $group->count() > 1);

            if ($duplicateGroup) {
                $v->errors()->add('parameters', 'برای هر گروه فقط یک گزینه مجاز است.');

                return;
            }

            $valuesByCode = $valuesCollection->keyBy(fn ($value) => $value->group->code);

            $customerType = $valuesByCode['customer_type'] ?? null;
            $eliteYarStatus = $valuesByCode['elite_yar_status'] ?? null;

            if (! $customerType || ! $eliteYarStatus) {
                $v->errors()->add(
                    'parameters',
                    'لطفاً هم "نوع مشتری" و هم "وضعیت الیت یار" را انتخاب کنید.'
                );

                return;
            }

            $customerTypeCode = $customerType->code;
            $eliteYarStatusCode = $eliteYarStatus->code;

            $isEngineerOrContractor = in_array($customerTypeCode, ['engineer', 'contractor']);
            $isWithoutEliteYar = $eliteYarStatusCode === 'without_elite_yar';

            if ($isEngineerOrContractor && $isWithoutEliteYar) {
                $v->errors()->add(
                    'parameters',
                    'مهندسان و پیمانکاران حتماً باید گزینه "دارای الیت یار" را انتخاب کنند.'
                );
            }
        });
    }
}
