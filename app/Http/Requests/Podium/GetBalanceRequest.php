<?php

namespace App\Http\Requests\Podium;

use App\Http\Requests\Concerns\ReadsScalarInput;
use Illuminate\Foundation\Http\FormRequest;

class GetBalanceRequest extends FormRequest
{
    use ReadsScalarInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $guild = $this->filled('guildCode') ? strtoupper(trim($this->scalarInput('guildCode'))) : null;

        if (! empty($guild)) {
            $guild = preg_replace('/[^A-Z0-9_.\-]/', '', $guild);
        }
        $this->merge(['guildCode' => $guild]);
    }

    public function rules(): array
    {
        return [
            'guildCode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_.\-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'guildCode.regex' => 'کد صنف فقط می‌تواند شامل حروف بزرگ، اعداد و .-_ باشد.',
        ];
    }
}
