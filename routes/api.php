<?php

use App\Http\Controllers\Api\User\ContractorController;
use App\Http\Controllers\Api\User\EmployerController;
use App\Http\Controllers\Api\User\EngineerController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\SupplierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('check-certificate', function (){

     $auth = array(
        "User"=>"SpidtechCRM",
        "Pass"=>"ha@s86h8a8l9#ha5f8t6pas5s8wo_rd"
    );

    $this->soapWrapper = new \Artisaninweb\SoapWrapper\SoapWrapper();

    $response = $this->soapWrapper->call('https://www.saze90.com/api/?wsdl');

    dd($response);


    if (is_soap_fault($client)) {
        trigger_error("SOAP Fault: (faultcode: {$client->faultcode}, faultstring: {$client->faultstring})", E_USER_ERROR);
    }
    $params = array("Auth" => $auth, "UserID" => 1, 'CustomerType' => 2);

    $response = $client->getUser(json_encode($params));
    dd($response);
    $response = json_decode($response);
    return $response;


    $response = \Illuminate\Support\Facades\Http::get('https://www.saze90.com/api/?wsdl');
    dd($response->getBody());
});

Route::controller(AuthController::class)->group(function () {
    Route::group(['prefix' => 'authenticate'], function () {
        Route::post('/', 'register');
        Route::post('/verify-code', 'verifyCode');
        Route::post('/password/check', 'checkPassword');
        Route::post('/forget-password', 'forgetPassword');
        Route::post('/change-password','changePassword');
    });
});

Route::middleware('auth:sanctum')->group(function () {

    Route::group(['prefix' => 'province'], function () {
        Route::get('/', function () {
            return success(getProvince());
        });
        Route::get('/cities/{provinceID?}', function () {
            return success(getCities());
        });
    });

    Route::group(['prefix' => 'profile'], function () {
        Route::get('/messages', [ProfileController::class, 'getMessages']);
        Route::get('/messages/read-all', [ProfileController::class, 'updateAllMessageStatus']);
        Route::get('/messages/{userNotification}', [ProfileController::class, 'updateMessageStatus']);
        Route::group(['prefix' => 'change-mobile'], function () {
            Route::get('/', [ProfileController::class, 'updateMobile']);
            Route::post('/verify-code', [ProfileController::class, 'verifyCode']);
            Route::post('/confirm', [ProfileController::class, 'confirmUpdateMobile']);
        });

        Route::get('/', [ProfileController::class, 'getProfile']);
        Route::post('/', [ProfileController::class, 'setProfile']);
        Route::put('/', [ProfileController::class, 'updateProfile']);
        Route::post('/password', [ProfileController::class, 'setPassword']);
        Route::get('/change-password/first-attempt', [ProfileController::class, 'changePasswordFirstAttempt']);
        Route::post('/type', [ProfileController::class, 'setUserType']);
        Route::group(['prefix' => 'engineer'], function () {
            Route::post('/', [EngineerController::class, 'store']);
        });

        Route::group(['prefix' => 'contractor'], function () {
            Route::post('/', [ContractorController::class, 'store']);
        });

        Route::group(['prefix' => 'supplier'], function () {
            Route::post('/', [SupplierController::class, 'store']);
        });

        Route::group(['prefix' => 'employer'], function () {
            Route::post('/', [EmployerController::class, 'store']);
        });
    });

    Route::get('/user-types', [AuthController::class, 'types']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

