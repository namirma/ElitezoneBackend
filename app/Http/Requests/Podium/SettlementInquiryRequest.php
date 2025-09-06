<?php

namespace App\Http\Requests\Podium;

use Illuminate\Foundation\Http\FormRequest;

class SettlementInquiryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $toEn = static fn($v) => strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);

        $offset = $this->filled('offset') ? (int)$toEn($this->input('offset')) : 0;
        $size   = $this->filled('size')   ? (int)$toEn($this->input('size'))   : 20;

        $toolCode     = $this->filled('toolCode')     ? strtoupper(trim((string)$this->input('toolCode'))) : null;
        $currencyCode = $this->filled('currencyCode') ? strtoupper(trim((string)$this->input('currencyCode'))) : 'IRR';
        $statusCode   = $this->filled('statusCode')   ? strtoupper(trim((string)$this->input('statusCode'))) : null;
        $toolId       = $this->filled('toolId')       ? strtoupper(preg_replace('/\s+/', '', (string)$this->input('toolId'))) : null;
        $uniqueId     = $this->filled('uniqueId')     ? preg_replace('/[^A-Za-z0-9\-]/', '', (string)$this->input('uniqueId')) : null;
        $id           = $this->filled('id')           ? (int)$toEn($this->input('id')) : null;
        $fromAmount   = $this->filled('fromAmount')   ? (int)$toEn($this->input('fromAmount')) : null;
        $toAmount     = $this->filled('toAmount')     ? (int)$toEn($this->input('toAmount')) : null;

        $fromDate = $this->filled('fromDate') ? trim((string)$this->input('fromDate')) : null;
        $toDate   = $this->filled('toDate')   ? trim((string)$this->input('toDate'))   : null;

        $this->merge(compact(
            'offset','size','toolCode','currencyCode','statusCode','toolId',
            'uniqueId','id','fromAmount','toAmount','fromDate','toDate'
        ));
    }

    public function rules(): array
    {
        return [
            'offset'       => ['required','integer','min:0'],
            'size'         => ['required','integer','min:1','max:200'],
            'uniqueId'     => ['nullable','string','max:100','regex:/^[A-Za-z0-9\-]+$/'],
            'id'           => ['nullable','integer','min:1'],
            'statusCode'   => ['nullable','in:SETTLEMENT_REQUESTED,SETTLEMENT_SENT,SETTLEMENT_DONE'],
            'toolCode'     => ['nullable','in:SETTLEMENT_TOOL_PAYA,SETTLEMENT_TOOL_SATNA,SETTLEMENT_TOOL_CARD'],
            'toolId'       => ['nullable','string','max:64'],
            'currencyCode' => ['nullable','in:IRR'],
            'fromAmount'   => ['nullable','integer','min:0'],
            'toAmount'     => ['nullable','integer','min:0'],
            'fromDate'     => ['nullable','regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'toDate'       => ['nullable','regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'firstName'    => ['nullable','string','max:100'],
            'lastName'     => ['nullable','string','max:100'],
            'invoiceId'    => ['nullable','string','max:100'],
            'paymentId'    => ['nullable','string','max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'fromDate.regex' => 'فرمت تاریخ باید yyyy/mm/dd (شمسی) باشد.',
            'toDate.regex'   => 'فرمت تاریخ باید yyyy/mm/dd (شمسی) باشد.',
        ];
    }
}
