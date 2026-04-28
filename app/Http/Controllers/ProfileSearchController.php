<?php

namespace App\Http\Controllers;

use App\Actions\Profile\ProfileNavigationAction;
use App\Models\ProfileSearchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileSearchController extends Controller
{
    /**
     * گرفتن اطلاعات اولیه یا سرچ کردن
     */
    public function index(Request $request, ProfileNavigationAction $action): JsonResponse
    {
        $keyword = $request->query('keyword');

        // اگر کاربر در حال تایپ است، فقط دسترسی‌های سریع فیلتر شده را بده
        if ($keyword) {
            return success([
                'quick_access' => $action->search($keyword)
            ], 'نتیجه جستجو');
        }

        // اگر تازه روی باکس کلیک کرده (بدون سرچ)، هر ۳ بخش را بده
        return success($action->getInitialData(), 'اطلاعات اولیه جستجوی پروفایل');
    }

    /**
     * وقتی کاربر روی یکی از چیپ‌ها (Chips) یا نتایج کلیک کرد، این را صدا می‌زنیم تا ذخیره شود
     */
    public function logSearch(Request $request): JsonResponse
    {
        $request->validate(['keyword' => 'required|string|max:50']);

        ProfileSearchHistory::create([
            'user_id' => Auth::id(),
            'keyword' => $request->input('keyword')
        ]);

        return success([], 'جستجو با موفقیت ثبت شد.');
    }

    /**
     * پاک کردن "آخرین جستجوهای شما" (آیکون سطل زباله در دیزاین)
     */
    public function clearHistory(): JsonResponse
    {
        ProfileSearchHistory::where('user_id', Auth::id())->delete();

        return success([], 'تاریخچه جستجو پاک شد.');
    }
}
