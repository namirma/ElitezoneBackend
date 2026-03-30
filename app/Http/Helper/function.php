<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

function faToEn($string): array|string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic = ['٩', '٨', '٧', '٦', '٥', '٤', '٣', '٢', '١', '٠'];

    $num = range(0, 9);
    $convertedPersianNums = str_replace($persian, $num, $string);

    return str_replace($arabic, $num, $convertedPersianNums);
}

function success($data, string $message = "global.received_successfully", int $status = 200, array $headers = [])
{
    return Response::success($data, __($message), $status, $headers);
}

function error(string $message, array $errors, int $status)
{
    return Response::error(__($message), $errors, $status);
}

function generateVerifyCode(): int
{
    return mt_rand(123456, 987654);
}

function getProvince(): Collection
{
    return DB::table('province')->get();
}

function getCities($provinceID = null): Collection
{
    $id = $provinceID ?? request('provinceID');

    return is_null($id)
        ? DB::table('province_city')->get()
        : DB::table('province_city')->where(['ProvinceID' => $id])->get();
}

function getSupplierId(): Collection
{
    if (is_null(request('activity_category_id'))) {
        return DB::table('supplier_activities')->pluck('activity_category_id');
    } else {
        return DB::table('supplier_activities')
            ->where('activity_category_id', request('activity_category_id'))
            ->pluck('activity_category_id');
    }
}

function getSupplierCategoryId(): Collection
{
    if (is_null(request('activity_category_id'))) {
        return DB::table('supplier_activities')->pluck('activity_category_id');
    } else {
        return DB::table('supplier_activities')
            ->where('activity_category_id', request('activity_category_id'))
            ->pluck('activity_category_id');
    }
}

function created($data = null, string $message = 'global.item_created_successfully'): JsonResponse
{
    return Response::success($data, __($message), 201);
}
