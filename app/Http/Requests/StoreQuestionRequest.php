<?php

namespace App\Http\Requests;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            Question::NAME => 'required|string|max:255',
            Question::MOBILE => 'required|string|max:11',
            Question::EMAIL => 'required|email:rfc,dns',
            Question::SUBJECT => 'required|string|', //. Rule::in(Question::$qTypes),
            Question::MESSAGE => 'required|string|max:1000',
        ];
    }
}
