<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PodiumClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class IdentityInquirySc2Controller extends Controller
{
    public function __invoke(Request $request, PodiumClient $client)
    {
        $data = $request->validate([
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'birth_date'    => ['required', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
        ]);

        $svc = Config::get('podium.services.identity_inquiry');

        $payload = [
            'apiKey'             => $svc['sc_api_key'],
            'productEntityId'    => (int) $svc['sc_product_id'],
            'providerParameters' => [
                'nationalCode' => $data['national_code'],
                'birthDate'    => str_replace('-', '', $data['birth_date']),
            ],
            'asyncCall' => false,
            'wrapper'   => true,
        ];

        $norm = PodiumClient::normalize($client->callSc2($payload));

        $info = data_get($norm, 'result.identityInfo', []);
        $bd   = (string) data_get($norm, 'result.birthDate');
        if (preg_match('/^\d{8}$/', $bd)) {
            $bd = substr($bd, 0, 4) . '-' . substr($bd, 4, 2) . '-' . substr($bd, 6, 2);
        }

        $aliveRaw = data_get($info, 'alive');
        $alive    = is_bool($aliveRaw) ? ($aliveRaw ? 'true' : 'false') : (string) $aliveRaw;

        return success([
            'reference_number'             => (string) data_get($norm, 'reference_number'),
            'national_code'                => (string) data_get($norm, 'result.nationalCode'),
            'birth_date'                   => (string) $bd,
            'first_name'                   => (string) data_get($info, 'firstName'),
            'last_name'                    => (string) data_get($info, 'lastName'),
            'father_name'                  => (string) data_get($info, 'fatherName'),
            'gender'                       => (string) data_get($info, 'gender'),
            'alive'                        => $alive,
            'birth_place'                  => (string) data_get($info, 'birthPlace'),
            'birth_place_code'             => (string) data_get($info, 'birthPlaceCode'),
            'identification_number'        => (string) data_get($info, 'identificationNumber'),
            'identification_serial_code'   => (string) data_get($info, 'identificationSerialCode'),
            'identification_serial_number' => (string) data_get($info, 'identificationSerialNumber'),
        ], 'اطلاعات با موفقیت دریافت شد.');
    }
}
