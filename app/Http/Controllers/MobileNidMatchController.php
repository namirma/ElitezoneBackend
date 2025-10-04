<?php

namespace App\Http\Controllers;

use App\Services\Gateways\GatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class MobileNidMatchController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'mobile_number' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $svc    = Config::get('podium.services.nid_mobile_match', []);
        $apiKey = (string) ($svc['sc_api_key']    ?? '');
        $prodId = (int)    ($svc['sc_product_id'] ?? 0);

        $mobile = $this->to09($data['mobile_number']);

        $payload = [
            'apiKey'             => $apiKey,
            'productEntityId'    => $prodId,
            'providerParameters' => [
                'body' => [
                    'nationalCode' => (string) $data['national_code'],
                    'mobileNumber' => (string) $mobile,
                ],
            ],
            'asyncCall' => false,
            'wrapper'   => true,
        ];

        $gateway = GatewayFactory::make('podium_sc2');
        $res     = $gateway->getRequest('/srv/sc2/consumers/services/do', $payload);

        $result = $res['result'] ?? null;
        if (is_string($result) && (str_starts_with($result, '{') || str_starts_with($result, '['))) {
            $decoded = json_decode($result, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $res['result'] = $decoded;
            }
        }

        $matched    = data_get($res, 'result.matched');
        $resultCode = (string) data_get($res, 'result.resultCode', '');
        $provMsg    = (string) data_get($res, 'result.message', '');

        $isSuccess = 'null';
        if (is_bool($matched)) {
            $isSuccess = $matched ? 'true' : 'false';
        } elseif (in_array(strtolower((string) $matched), ['true', 'false', 'null'], true)) {
            $isSuccess = strtolower((string) $matched);
        } elseif ($resultCode !== '') {
            $isSuccess = $resultCode === '1' ? 'true' : ($resultCode === '0' ? 'false' : 'null');
        }

        $message = $provMsg !== '' ? $provMsg : ($isSuccess === 'true' ? 'تطابق تأیید شد' : ($isSuccess === 'false' ? 'مغایرت اطلاعات' : 'نتیجه نامشخص'));

        return success([
            'reference_number' => (string) data_get($res, 'referenceNumber', ''),
            'national_code'    => (string) $data['national_code'],
            'mobile_number'    => (string) $mobile,
            'is_success'       => (string) $isSuccess,
            'result_code'      => (string) $resultCode,
            'provider_message' => (string) $provMsg,
        ], $message);
    }

    private function to09(string $raw): string
    {
        $m = preg_replace('/\D/', '', $raw);
        if (str_starts_with($m, '0098')) $m = substr($m, 2);
        if (str_starts_with($m, '98'))   return '0' . substr($m, 2);
        if (str_starts_with($m, '9'))    return '0' . $m;
        return $m;
    }
}
