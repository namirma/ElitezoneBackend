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

/**
 * Convert ASCII digits to Persian digits (۰-۹) for display — letters/symbols untouched.
 * Mirrors how the "Yekan Bakh FaNum" design font renders numerals, so PDFs/invoices match
 * the UI/UX spec exactly.
 */
function enToFa(string|int|float|null $value): string
{
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    return str_replace($en, $fa, (string) $value);
}

function success($data, string $message = 'global.received_successfully', int $status = 200, array $headers = [])
{
    return Response::success($data, __($message), $status, $headers);
}

function error(string $message, array $errors, int $status)
{
    return Response::error(__($message), $errors, $status);
}

function generateVerifyCode(): int
{
    // Use a cryptographically-secure PRNG for OTP / password-reset codes.
    // random_int() (CSPRNG) is unpredictable, unlike mt_rand(). Full 6-digit range.
    return random_int(100000, 999999);
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
