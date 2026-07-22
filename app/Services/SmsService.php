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
        // مقدار پیش‌فرض '' تضمین می‌کند که در محیط تست (که env نیست)
        // PHP 8.1 TypeError برای property string نگیرد.
        $this->link = config('services.tsms.link', '');
        $this->from = config('services.tsms.number', '');
        $this->username = config('services.tsms.username', '');
        $this->password = config('services.tsms.password', '');
    }

    public function send($to, $message)
    {
        // اگر سرویس تنظیم نشده باشد (محیط تست/استیجینگِ بدون TSMS) این یک no-op است، نه
        // «شکستِ تحویل». true برمی‌گردانیم تا جاب‌ها (که حالا روی false تلاش مجدد می‌کنند)
        // در محیطِ بدون‌پیکربندی به‌اشتباه fail/retry نشوند.
        if (empty($this->link) || empty($this->from)) {
            Log::warning('SmsService: TSMS پیکربندی نشده، ارسال پیامک نادیده گرفته شد.', ['to' => $to]);

            return true;
        }

        try {
            // از POST به‌جای GET استفاده می‌کنیم تا اطلاعات احراز هویت در URL دیده نشوند.
            // با GET، username و password در access log سرور پیامک و در لاگ‌های پروکسی
            // به‌صورت plain-text ذخیره می‌شدند — یک آسیب‌پذیری امنیتی جدی.
            // با asForm()->post()، پارامترها در request body قرار می‌گیرند (application/x-www-form-urlencoded)
            // و از دید لاگ‌های HTTP دور می‌مانند. timeout هم گذاشته شد تا درگاهِ کند
            // هرگز worker/تراکنش را بیش از ۱۵ ثانیه بلاک نکند.
            $response = Http::asForm()->timeout(15)->post($this->link, [
                'from' => $this->from,
                'to' => $to,
                'username' => $this->username,
                'password' => $this->password,
                'message' => $message,
            ]);

            // پاسخِ non-2xx یعنی شکستِ ارسال — قبلاً body برگردانده می‌شد (truthy) و
            // شکست به‌عنوان موفقیت تلقی می‌شد؛ حالا false می‌دهیم تا retry فعال شود.
            if (! $response->successful()) {
                Log::error('TSMS non-success response', ['to' => $to, 'status' => $response->status()]);

                return false;
            }

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
