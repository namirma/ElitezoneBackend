<?php

namespace App\Services;

use App\Http\Classes\Constant;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private string $link, $from, $username, $password;

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
            // استفاده از آرایه برای ارسال امن پارامترها (جلوگیری از باگ‌های URL Encoding)
            $response = Http::get($this->link, [
                'from'     => $this->from,
                'to'       => $to,
                'username' => $this->username,
                'password' => $this->password,
                'message'  => $message,
            ]);

            return $response->body();
        } catch (Exception $e) {
            // ثبت خطاهای احتمالی (مثل تایم‌اوت شدن سرور پیامک) در لاگ برای دیباگ راحت‌تر
            Log::error('TSMS Send Error: ' . $e->getMessage());
            return false;
        }
    }
}
