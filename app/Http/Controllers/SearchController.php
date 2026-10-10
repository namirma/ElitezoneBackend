<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\SearchRequest;
use App\Http\Resources\Product\SearchResource;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Search;
use App\Models\SupplierActivityCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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

    /** سقفِ نتیجه در هر گروه. */
    private const SITE_LIMIT = 20;

    /**
     * Site-wide search: published products/variants, active CMS pages and FAQ questions.
     */
    public function site(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        // ارقامِ فارسی/عربی → لاتین و «ي/ك/ى» → «ی/ک»؛ بعد هر ترکیبِ حروف × ارقام، چون
        // داده‌ی ذخیره‌شده هر شکلی می‌تواند باشد («میلگرد ۱۲» یا «ميلگرد 12»).
        // ponytail: رشته‌ای که هر دو شکلِ حروف را با هم دارد پیدا نمی‌شود؛ اگر لازم شد، ستونِ نرمال‌شده.
        $q = trim((string) preg_replace('/\s+/u', ' ', strtr(faToEn((string) $request->input('q')), ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی'])));
        $patterns = collect([$q, strtr($q, ['ی' => 'ي', 'ک' => 'ك'])])
            ->flatMap(fn (string $v) => [$v, enToFa($v)])
            ->unique()
            ->map(fn (string $v) => '%'.addcslashes($v, '%_\\').'%')
            ->all();

        $matching = fn (string $column) => function (Builder $w) use ($column, $patterns) {
            foreach ($patterns as $pattern) {
                $w->orWhere($column, 'like', $pattern);
            }
        };

        $variants = ProductVariant::query()
            ->active()
            ->catalogVisible()
            ->whereHas('product', fn ($p) => $p->where(Product::IS_ACTIVE, true))
            ->where($matching(ProductVariant::NAME))
            ->with('product:id,cuid,name')
            ->orderBy(ProductVariant::ID)
            ->limit(self::SITE_LIMIT)
            ->get();

        return success([
            'query' => $q,
            'products' => Product::query()
                ->active()
                ->where($matching(Product::NAME))
                ->orderBy(Product::ID)
                ->limit(self::SITE_LIMIT)
                ->get()
                ->map(fn (Product $p) => ['cuid' => $p->cuid, 'name' => $p->name]),
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'product_cuid' => $v->product->cuid,
                'product_name' => $v->product->name,
            ]),
            'pages' => Page::query()
                ->where(Page::IS_ACTIVE, true)
                ->where($matching(Page::TITLE))
                ->orderBy(Page::ID)
                ->limit(self::SITE_LIMIT)
                ->get()
                // همان قاعده‌ی `Page::blockPages()`: صفحه‌ی بی‌مسیرِ اختصاصی زیرِ `/p/` است.
                ->map(fn (Page $p) => ['slug' => $p->slug, 'title' => $p->title, 'route' => $p->route ?: '/p/'.$p->slug]),
            'faqs' => Faq::query()
                ->where(Faq::IS_ACTIVE, true)
                ->where($matching(Faq::QUESTION))
                ->orderBy(Faq::SORT_ORDER)
                ->orderBy(Faq::ID)
                ->limit(self::SITE_LIMIT)
                ->get()
                ->map(fn (Faq $f) => ['id' => $f->id, 'question' => $f->question, 'faq_category_id' => $f->faq_category_id]),
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
                if ($this->nameMatches($category->name, $query)) {
                    $matches->push($category->name);
                }

                // اگر اسم زیرمجموعه‌ها شبیه بود
                $childMatches = $category->children
                    ->filter(fn ($child) => $this->nameMatches($child->name, $query))
                    ->pluck('name');

                return $matches->merge($childMatches);
            })
            ->unique();
    }

    /**
     * آیا این نام با عبارتِ جستجو می‌خواند — با **همان حساسیتِ حروفی که SQL دارد**.
     *
     * ⚠️ فیلترِ قبلی `Str::contains()` بود که حساس به بزرگی/کوچکیِ حروف است، در
     * حالی که کوئریِ `LIKE` روی collation‌ـی مثل `utf8mb4_unicode_ci` **نیست**.
     * پس هر دسته‌ای با نامِ لاتین از دیتابیس برمی‌گشت و بعد در PHP دور ریخته
     * می‌شد: جستجوی «steel» ردیفِ «Steel Co» را می‌آورد و همان‌جا حذفش می‌کرد.
     * نتیجه صفر نتیجه بود و `results_count` هم صفر لاگ می‌شد — یعنی داده‌ی
     * آنالیتیکس هم همراهش خراب می‌شد.
     *
     * `mb_stripos` و نه `Str::contains(..., ignoreCase: true)`: چندبایتی است
     * (نامِ فارسی) و روی هر دو شاخه‌ی L10/L13 یکسان رفتار می‌کند.
     */
    private function nameMatches(?string $name, string $query): bool
    {
        if ($name === null || $query === '') {
            return false;
        }

        return mb_stripos($name, $query) !== false;
    }

    /**
     * ثبت اطلاعات در دیتابیس برای تحلیل داده
     *
     * IP مثل LogProductView ناشناس می‌شود (اکتت/هکستت آخر صفر) — آنالیتیکس به
     * subnet نیاز دارد نه IP دقیق، و نگه‌داری IP خام کاربرِ لاگین‌نکرده با سیاست
     * حریم خصوصی بقیه‌ی سیستم (product_views) ناسازگار بود.
     */
    private function logSearchActivity(SearchRequest $request, string $query, int $resultsCount): void
    {
        Search::create([
            Search::QUERY => $query,
            Search::RESULTS_COUNT => $resultsCount,
            Search::USER_ID => Auth::id(),
            Search::IP_ADDRESS => $this->anonymizeIp((string) $request->ip()),
            Search::USER_AGENT => $request->userAgent(),
            Search::CREATED_AT => now(),
        ]);
    }

    private function anonymizeIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            return preg_replace('/:[0-9a-fA-F]*$/', ':0', $ip) ?? $ip;
        }

        return preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
    }

    /**
     * دریافت آخرین جستجوهای **خودِ کاربر**.
     *
     * ⚠️ `/search` عمومی است و برای مهمان هیچ فیلترِ `user_id` وجود نداشت، پس
     * شرطِ `if (Auth::check())` یعنی مهمان **۵ جستجوی آخرِ کلِ سیستم** را
     * می‌گرفت — یعنی عبارت‌هایی که کاربرانِ دیگر تایپ کرده بودند، زیر عنوانِ
     * «آخرین جستجوهای شما». هم نشتِ داده‌ی بین‌کاربری بود هم صریحاً غلط.
     *
     * مهمان تاریخچه‌ی شخصی ندارد، پس جوابِ درست خالی است نه تاریخچه‌ی دیگران.
     */
    private function getLatestSearches(): Collection
    {
        if (! Auth::check()) {
            return collect();
        }

        // ‏`GROUP BY` + `MAX(created_at)` و نه `DISTINCT` + `ORDER BY created_at`:
        // MySQL 8 دومی را رد می‌کند (خطای 3065 — ستونِ مرتب‌سازی در SELECTِ
        // distinct نیست) و جستجوی هر کاربرِ واردشده ۵۰۰ می‌داد. MariaDBِ لوکال
        // قبولش می‌کند، پس فقط CI روی MySQL 8 آن را گرفت. معنا هم دقیق‌تر شد:
        // هر عبارت یک‌بار، به ترتیبِ **آخرین** باری که جستجو شده.
        return Search::query()
            ->where(Search::USER_ID, Auth::id())
            ->groupBy(Search::QUERY)
            ->orderByRaw('MAX('.Search::CREATED_AT.') DESC')
            ->limit(5)
            ->pluck(Search::QUERY);
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
