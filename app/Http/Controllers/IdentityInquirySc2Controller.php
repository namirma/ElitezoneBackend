<?php

namespace App\Http\Controllers;

use App\Services\Gateways\GatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class IdentityInquirySc2Controller extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'birth_date'    => ['required', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
        ]);

        $svc   = Config::get('podium.services.identity_inquiry', []);
        $apiKey = (string) ($svc['sc_api_key']    ?? '');
        $prodId = (int)    ($svc['sc_product_id'] ?? 0);
        $birth  = str_replace('-', '', $data['birth_date']);

        $payload = [
            'apiKey'             => $apiKey,
            'productEntityId'    => $prodId,
            'providerParameters' => [
                'nationalCode' => $data['national_code'],
                'birthDate'    => $birth,
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

        $info = data_get($res, 'result.identityInfo', []);
        $bd   = (string) data_get($res, 'result.birthDate', '');
        if (preg_match('/^\d{8}$/', $bd)) {
            $bd = substr($bd, 0, 4) . '-' . substr($bd, 4, 2) . '-' . substr($bd, 6, 2);
        }

        return response()->json([
            'status'  => ! (bool) data_get($res, 'hasError', true),
            'message' => data_get($res, 'hasError') ? 'خطا در استعلام هویتی' : 'اطلاعات با موفقیت دریافت شد.',
            'data'    => [
                'reference_number'             => (string) data_get($res, 'referenceNumber', ''),
                'national_code'                => (string) data_get($res, 'result.nationalCode', ''),
                'birth_date'                   => (string) $bd,
                'first_name'                   => (string) data_get($info, 'firstName', ''),
                'last_name'                    => (string) data_get($info, 'lastName', ''),
                'father_name'                  => (string) data_get($info, 'fatherName', ''),
                'gender'                       => (string) data_get($info, 'gender', ''),
                'alive'                        => (string) (is_bool(data_get($info, 'alive')) ? (data_get($info, 'alive') ? 'true' : 'false') : (string) data_get($info, 'alive')),
                'birth_place'                  => (string) data_get($info, 'birthPlace', ''),
                'birth_place_code'             => (string) data_get($info, 'birthPlaceCode', ''),
                'identification_number'        => (string) data_get($info, 'identificationNumber', ''),
                'identification_serial_code'   => (string) data_get($info, 'identificationSerialCode', ''),
                'identification_serial_number' => (string) data_get($info, 'identificationSerialNumber', ''),
            ],
        ], Response::HTTP_OK);
    }
}
