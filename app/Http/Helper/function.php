<?php

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

function getCities(): Collection
{
    return is_null(\request('provinceID')) ? DB::table('province_city')->get() : DB::table('province_city')->where(['ProvinceID' => \request('provinceID')])->get();
}
