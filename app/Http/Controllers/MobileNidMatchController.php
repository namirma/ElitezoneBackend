<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PodiumClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;


class MobileNidMatchController extends Controller
{
    public function store(Request $request, PodiumClient $client)
    {
        $data = $request->validate([
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'mobile_number' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $apiKey    = (string) (Config::get('podium.services.nid_mobile_match.sc_api_key') ?? env('PODIUM_SC_API_KEY_MATCH_MOBILE_NID'));
        $productId = (int)    (Config::get('podium.services.nid_mobile_match.sc_product_id') ?? 46645324);

        $mobile = $this->to09Format($data['mobile_number']);

        $payload = [
            'apiKey'          => $apiKey,
            'productEntityId' => $productId,
            'providerParameters' => [
                'body' => [
                    'nationalCode' => (string) $data['national_code'],
                    'mobileNumber' => (string) $mobile, // مثال: 0914...
                ],
            ],
            'asyncCall' => false,
            'wrapper'   => true,
        ];

        $norm       = PodiumClient::normalize($client->callSc2($payload));
        $matched    = data_get($norm, 'result.matched');
        $resultCode = (string) data_get($norm, 'result.resultCode', '');
        $provMsg    = (string) data_get($norm, 'result.message', '');

        $isSuccess = is_bool($matched) ? ($matched ? 'true' : 'false') : (string) $matched;
        if ($isSuccess === '' && $resultCode !== '') {
            $isSuccess = $resultCode === '1' ? 'true' : ($resultCode === '0' ? 'false' : 'null');
        }

        $message = $provMsg !== '' ? $provMsg : ($isSuccess === 'true' ? 'تطابق تأیید شد' : ($isSuccess === 'false' ? 'مغایرت اطلاعات' : 'نتیجه نامشخص'));

        return success([
            'reference_number' => (string) data_get($norm, 'reference_number', ''),
            'national_code'    => (string) $data['national_code'],
            'mobile_number'    => (string) $mobile,
            'is_success'       => (string) $isSuccess,
            'result_code'      => (string) $resultCode,
            'provider_message' => (string) $provMsg,
        ], $message);
    }

    private function to09Format(string $input): string
    {
        $m = preg_replace('/\D/', '', $input);
        if (str_starts_with($m, '0098')) $m = substr($m, 2); // 0098... -> 98...
        if (str_starts_with($m, '98'))   return '0' . substr($m, 2); // 98... -> 09...
        if (str_starts_with($m, '9'))    return '0' . $m;            // 9...  -> 09...
        return $m; // اگر خودش 09... بود
    }
}
