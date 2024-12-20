<?php

namespace App\Http\Requests;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            Question::NAME => 'required|string|max:255',
            Question::PHONE => 'required|string|max:11',
            Question::EMAIL => 'required|email:rfc,dns',
            Question::SUBJECT => 'required|string|in:انتقاد و شکایات,مدیریت,حسابداری و امور مالی,پیشنهاد,گزارش خطا در نحوه عملکرد وب سایت,سایر موضوعات',
            Question::MESSAGE => 'required|string|max:1000',
        ];
    }
}
