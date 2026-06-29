<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (entry point)
|--------------------------------------------------------------------------
| این فایل به‌عنوان glue عمل می‌کند — هیچ روتی مستقیماً اینجا تعریف
| نمی‌شود. هر گروه در subfile جداگانه داخل routes/api/ زندگی می‌کند:
|
|   routes/api/public.php   ← Public + Authentication endpoints
|   routes/api/user.php     ← Authenticated user area
|   routes/api/admin.php    ← Admin panel + Podium services
|
| روتهای v1 ticket که به‌خاطر سازگاری legacy نگه داشته شده‌اند:
*/

Route::prefix('ticket')->group(__DIR__.'/v1/api.php');

require __DIR__.'/api/public.php';
require __DIR__.'/api/user.php';
require __DIR__.'/api/admin.php';
