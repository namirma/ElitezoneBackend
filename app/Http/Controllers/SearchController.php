<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\SearchRequest;
use App\Http\Resources\Product\SearchResource;
use App\Models\Search;
use App\Models\SupplierActivityCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * متد اصلی که روت (Route) صدا می‌زند
     */
    public function search(SearchRequest $request)
    {
        // ۱. دریافت ورودی
        $query = trim($request->input('query', ''));

        if (empty($query)) {
            return $this->defaultResponse();
        }

        // ۲. اینجا تابع کمکی پایین صفحه را صدا می‌زنیم
        $searchResults = $this->performCategorySearch($query);

        // شمارش نتایج برای دیتاساینس
        $totalResultsCount = $searchResults->count();

        // ۳. ذخیره لاگ (تابع کمکی پایین صفحه)
        $this->logSearchActivity($request, $query, $totalResultsCount);

        // ۴. آماده‌سازی خروجی
        $relatedSearches = $searchResults->take(5)->values();
        $latestSearches = $this->getLatestSearches();
        $popularSearches = $this->getPopularSearches();

        return new SearchResource([
            'related_searches' => $relatedSearches,
            'latest_searches' => $latestSearches,
            'popular_searches' => $popularSearches,
        ]);
    }

    /**
     * منطق اصلی جستجو در دیتابیس (همان performSearchLogic)
     */
    private function performCategorySearch(string $query): Collection
    {
        // % و _ در LIKE معنای wildcard دارند — بدون escape، ورودی «%» همه ردیف‌ها
        // را برمی‌گرداند (full scan / enumeration).
        $escaped = addcslashes($query, '%_\\');

        return SupplierActivityCategory::query()
            ->where(function (Builder $q) use ($escaped) {
                $q->where('name', 'LIKE', "%{$escaped}%")
                    ->orWhereHas('children', function (Builder $subQ) use ($escaped) {
                        $subQ->where('name', 'LIKE', "%{$escaped}%");
                    });
            })
            ->with('children')
            ->get()
            ->flatMap(function ($category) use ($query) {
                $matches = collect();

                // اگر اسم دسته اصلی شبیه بود
                if (Str::contains($category->name, $query)) {
                    $matches->push($category->name);
                }

                // اگر اسم زیرمجموعه‌ها شبیه بود
                $childMatches = $category->children
                    ->filter(fn ($child) => Str::contains($child->name, $query))
                    ->pluck('name');

                return $matches->merge($childMatches);
            })
            ->unique();
    }

    /**
     * ثبت اطلاعات در دیتابیس برای تحلیل داده
     */
    private function logSearchActivity(SearchRequest $request, string $query, int $resultsCount): void
    {
        Search::create([
            Search::QUERY => $query,
            Search::RESULTS_COUNT => $resultsCount,
            Search::USER_ID => Auth::id(),
            Search::IP_ADDRESS => $request->ip(),
            Search::USER_AGENT => $request->userAgent(),
            Search::CREATED_AT => now(),
        ]);
    }

    /**
     * دریافت آخرین جستجوها
     */
    private function getLatestSearches(): Collection
    {
        $query = Search::select(Search::QUERY)
            ->distinct()
            ->orderBy(Search::CREATED_AT, 'desc')
            ->limit(5);

        if (Auth::check()) {
            $query->where(Search::USER_ID, Auth::id());
        }

        return $query->pluck(Search::QUERY);
    }

    /**
     * دریافت جستجوهای محبوب
     */
    private function getPopularSearches(): Collection
    {
        $validCategoriesSubQuery = function ($q) {
            $q->select('name')
                ->from('supplier_activity_categories')
                ->union(
                    SupplierActivityCategory::select('name')
                        ->whereHas('children')
                        ->getQuery()
                );
        };

        return Search::select(Search::QUERY)
            ->whereIn(Search::QUERY, $validCategoriesSubQuery)
            ->groupBy(Search::QUERY)
            ->orderByRaw('COUNT(*) DESC')
            ->limit(5)
            ->pluck(Search::QUERY);
    }

    /**
     * پاسخ پیش‌فرض
     */
    private function defaultResponse(): SearchResource
    {
        return new SearchResource([
            'related_searches' => [],
            'latest_searches' => [],
            'popular_searches' => $this->getPopularSearches(),
        ]);
    }
}
