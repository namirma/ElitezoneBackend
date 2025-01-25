<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\User\ContractorController;
use App\Http\Controllers\Api\User\EmployerController;
use App\Http\Controllers\Api\User\EngineerController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\SupplierController;
use App\Http\Controllers\SearchController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

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
Route::get('/search', [SearchController::class, 'search']);
Route::post('/new-question', [QuestionController::class, 'submitQuestion']);

Route::controller(AuthController::class)->group(function () {
    Route::group(['prefix' => 'authenticate'], function () {
        Route::post('/', 'register');
        Route::post('/verify-code', 'verifyCode');
        Route::post('/password/check', 'checkPassword');
        Route::post('/forget-password', 'forgetPassword');
        Route::post('/change-password', 'changePassword');
        Route::post('/send-code', 'sendVerificationCode');
        Route::middleware('auth:sanctum')->post('/logout', 'logout');
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
        Route::post('/verify-email', [ProfileController::class, 'setEmail']);
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
            Route::get('/activity-categories', [SupplierController::class, 'getCategories']);
            Route::post('/', [SupplierController::class, 'store']);
            Route::get('/activityCategory-getId', function () {
                return getSupplierCategoryId();
            });
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

Route::post('/check-email-token', [ProfileController::class, 'checkToken']);

Route::post('/check-saze-user', function (Request $request) {
    return [
        'result' => (int)User::query()->where(['mobile' => $request->input('mobile')])->exists()
    ] ;
});
