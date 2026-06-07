<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * مقدار per_page را از ریکوئست می‌خواند و آن را بین ۱ و $max محدود می‌کند.
     *
     * این متد از دو مشکل رایج جلوگیری می‌کند:
     *  - مقادیر منفی یا صفر (min ← 1)
     *  - درخواست‌های عمداً بزرگ (e.g., per_page=100000) که حافظه سرور را می‌گیرند (max ← 100)
     *
     * استفاده:
     *   ->paginate($this->perPage())         // default=20, max=100
     *   ->paginate($this->perPage(10))       // default=10, max=100
     *   ->paginate($this->perPage(10, 50))   // default=10, max=50
     */
    protected function perPage(int $default = 20, int $max = 100): int
    {
        return min(max(1, (int) request()->input('per_page', $default)), $max);
    }
}
