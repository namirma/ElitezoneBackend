<?php

namespace App\Services;

use App\Http\Classes\Constant;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private string $link;

    private string $from;

    private string $username;

    private string $password;

    public function __construct()
    {
        // فراخوانی امن مقادیر از کش کانفیگ به جای کلاس Constant
        $this->link = config('services.tsms.link');
        $this->from = config('services.tsms.number');
        $this->username = config('services.tsms.username');
        $this->password = config('services.tsms.password');
    }

    public function send($to, $message)
    {
        try {
            // از POST به‌جای GET استفاده می‌کنیم تا اطلاعات احراز هویت در URL دیده نشوند.
            // با GET، username و password در access log سرور پیامک و در لاگ‌های پروکسی
            // به‌صورت plain-text ذخیره می‌شدند — یک آسیب‌پذیری امنیتی جدی.
            // با asForm()->post()، پارامترها در request body قرار می‌گیرند (application/x-www-form-urlencoded)
            // و از دید لاگ‌های HTTP دور می‌مانند.
            $response = Http::asForm()->post($this->link, [
                'from' => $this->from,
                'to' => $to,
                'username' => $this->username,
                'password' => $this->password,
                'message' => $message,
            ]);

            return $response->body();
        } catch (Exception $e) {
            // اطلاعات حساس (username/password) هرگز در پیام خطا لاگ نمی‌شوند.
            Log::error('TSMS Send Error', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
