<?php

namespace App\Http\Requests\Podium;

use App\Support\IranIban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class BatchSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $toEn = static fn ($v) => strtr((string) $v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        $batchId = $this->filled('batchId') ? preg_replace('/[^A-Za-z0-9\-]/', '', (string) $this->input('batchId')) : null;

        $list = $this->input('list', []);
        if (is_string($list)) {
            $decoded = json_decode($list, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $list = $decoded;
            }
        }

        $norm = [];
        foreach ((array) $list as $i => $row) {
            $row['amount'] = isset($row['amount']) ? (int) $toEn($row['amount']) : null;
            $row['firstName'] = isset($row['firstName']) ? trim((string) $row['firstName']) : null;
            $row['lastName'] = isset($row['lastName']) ? trim((string) $row['lastName']) : null;
            $row['toolCode'] = isset($row['toolCode']) ? strtoupper(trim((string) $row['toolCode'])) : null;
            $row['toolId'] = isset($row['toolId']) ? strtoupper(preg_replace('/\s+/', '', (string) $row['toolId'])) : null;
            $row['wallet'] = isset($row['wallet']) ? strtoupper(trim((string) $row['wallet'])) : 'PODLAND_WALLET';
            $row['currencyCode'] = isset($row['currencyCode']) ? strtoupper(trim((string) $row['currencyCode'])) : 'IRR';
            $row['description'] = isset($row['description']) ? trim((string) $row['description']) : null;

            // ساختن uniqueId اگر نبود
            $uid = $row['uniqueId'] ?? null;
            if (empty($uid)) {
                $uid = sprintf(
                    '%s-%02d-%s',
                    $batchId ?: 'batch',
                    $i + 1,
                    strtoupper(Str::random(4))
                );
            }
            $row['uniqueId'] = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $uid);

            $norm[] = $row;
        }

        $this->merge([
            'batchId' => $batchId,
            'list' => $norm,
        ]);
    }

    public function rules(): array
    {
        return [
            'batchId' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'list' => ['required', 'array', 'min:1', 'max:500'],

            'list.*.amount' => ['required', 'integer', 'min:1000'],
            'list.*.firstName' => ['required', 'string', 'max:100'],
            'list.*.lastName' => ['nullable', 'string', 'max:100'],
            'list.*.toolCode' => ['required', 'in:SETTLEMENT_TOOL_PAYA,SETTLEMENT_TOOL_SATNA,SETTLEMENT_TOOL_CARD'],
            'list.*.toolId' => ['required', 'string', 'max:64'],
            'list.*.wallet' => ['nullable', 'in:PODLAND_WALLET'],
            'list.*.currencyCode' => ['nullable', 'in:IRR'],
            'list.*.description' => ['nullable', 'string', 'max:500'],
            'list.*.uniqueId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-]+$/'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            foreach ((array) $this->input('list', []) as $i => $row) {
                $code = (string) ($row['toolCode'] ?? '');
                $id = (string) ($row['toolId'] ?? '');

                if (in_array($code, ['SETTLEMENT_TOOL_PAYA', 'SETTLEMENT_TOOL_SATNA'], true)) {
                    if (! preg_match('/^IR\d{24}$/', $id) || ! IranIban::isValid($id)) {
                        $v->errors()->add("list.$i.toolId", 'شماره شبا نامعتبر است (IR + 24 رقم + checksum).');
                    }
                } elseif ($code === 'SETTLEMENT_TOOL_CARD') {
                    if (! preg_match('/^\d{16}$/', $id) || ! $this->passesLuhn($id)) {
                        $v->errors()->add("list.$i.toolId", 'شماره کارت نامعتبر است (16 رقم + Luhn).');
                    }
                }
            }
        });
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }$sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
