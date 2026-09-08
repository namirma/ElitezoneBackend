<?php

namespace App\Http\Controllers;

use App\Http\Resources\Content\ArticleResource;
use App\Http\Resources\Content\BannerResource;
use App\Http\Resources\Content\FaqCategoryResource;
use App\Http\Resources\Content\FaqResource;
use App\Http\Resources\Content\MenuItemResource;
use App\Http\Resources\Content\PageBlockResource;
use App\Http\Resources\Content\PageResource;
use App\Models\Article;
use App\Models\Banner;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * نمایش عمومی محتوای مدیریت‌شده در پنل ادمین (بنر/مقاله/FAQ/صفحات/تنظیمات عمومی).
 *
 * فقط رکوردهای فعال؛ مجموعه‌های کوچک (بنر/FAQ/صفحات) یک‌جا کش می‌شوند
 * (booted مدل‌ها کلید را flush می‌کند) و فیلتر در PHP انجام می‌شود.
 * مقالات (رشد نامحدود) paginate مستقیم DB هستند.
 */
class ContentController extends Controller
{
    // GET /banners?position=
    public function banners(Request $request): JsonResponse
    {
        $banners = Cache::remember(Banner::CACHE_KEY, 3600, fn () => Banner::query()
            ->where(Banner::IS_ACTIVE, true)
            ->orderBy(Banner::SORT_ORDER)
            ->orderByDesc(Banner::ID)
            ->get());

        if ($request->filled('position')) {
            $banners = $banners->where(Banner::POSITION, (string) $request->string('position'))->values();
        }

        return success(BannerResource::collection($banners), __('content.banners_received'));
    }

    // GET /articles?search=
    public function articles(Request $request): JsonResponse
    {
        $query = Article::query()
            ->where(Article::IS_ACTIVE, true)
            // انتشار زمان‌بندی‌شده: published_at آینده هنوز عمومی نیست
            ->where(fn ($q) => $q
                ->whereNull(Article::PUBLISHED_AT)
                ->orWhere(Article::PUBLISHED_AT, '<=', now()))
            ->orderByDesc(Article::PUBLISHED_AT)
            ->orderByDesc(Article::ID);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q
                ->where(Article::TITLE, 'like', "%{$search}%")
                ->orWhere(Article::EXCERPT, 'like', "%{$search}%"));
        }

        return success(ArticleResource::collection($query->paginate($this->perPage(12))), __('content.articles_received'));
    }

    // GET /articles/{slug}
    public function article(string $slug): JsonResponse
    {
        $article = Article::query()
            ->where(Article::SLUG, $slug)
            ->where(Article::IS_ACTIVE, true)
            ->where(fn ($q) => $q
                ->whereNull(Article::PUBLISHED_AT)
                ->orWhere(Article::PUBLISHED_AT, '<=', now()))
            ->first();

        if (! $article) {
            return error(__('content.article_not_found'), [], 404);
        }

        return success(new ArticleResource($article), __('content.article_received'));
    }

    // GET /faq?category=
    public function faq(Request $request): JsonResponse
    {
        $faqs = Cache::remember(Faq::CACHE_KEY, 3600, fn () => Faq::query()
            ->where(Faq::IS_ACTIVE, true)
            ->orderBy(Faq::SORT_ORDER)
            ->orderBy(Faq::ID)
            ->get());

        if ($request->filled('category')) {
            $faqs = $faqs->where(Faq::CATEGORY, (string) $request->string('category'))->values();
        }

        return success(FaqResource::collection($faqs), __('content.faq_received'));
    }

    /**
     * GET /faq-categories — دسته‌ها به‌همراه سوالاتشان (درختی).
     *
     * کشوی پشتیبانیِ سایت اصلی دقیقاً همین شکل را می‌خواهد: منوی دسته‌ها با
     * عنوان/زیرعنوان، و با کلیک روی هر دسته، سوالاتش. یک درخواست به‌جای N+1.
     * فقط دسته‌های فعال و فقط سوالات فعال — و ترتیب هر دو از `sort_order` پنل.
     */
    public function faqCategories(): JsonResponse
    {
        $categories = Cache::remember(FaqCategory::CACHE_KEY, 3600, fn () => FaqCategory::query()
            ->where(FaqCategory::IS_ACTIVE, true)
            ->with(['faqs' => fn ($q) => $q
                ->where(Faq::IS_ACTIVE, true)
                ->orderBy(Faq::SORT_ORDER)
                ->orderBy(Faq::ID),
            ])
            ->orderBy(FaqCategory::SORT_ORDER)
            ->orderBy(FaqCategory::ID)
            ->get());

        return success(FaqCategoryResource::collection($categories), __('content.faq_received'));
    }

    /**
     * GET /menu?location=header|footer — درختِ منوی سایت.
     *
     * `header` = مگامنوی نوار بالا، `footer` = ستون‌های لینکِ پایین صفحه.
     * تا سه سطح eager-load می‌شود (اصلی → سرستون → گزینه) و فقط شاخه‌های فعال.
     * آیتمی که `url` ندارد یک «عنوانِ بدون لینک» است، نه لینکِ مرده.
     */
    public function menu(Request $request): JsonResponse
    {
        $location = (string) $request->string('location', MenuItem::LOCATION_HEADER);
        if (! in_array($location, MenuItem::LOCATIONS, true)) {
            $location = MenuItem::LOCATION_HEADER;
        }

        $activeOrdered = fn ($q) => $q
            ->where(MenuItem::IS_ACTIVE, true)
            ->orderBy(MenuItem::SORT_ORDER)
            ->orderBy(MenuItem::ID);

        $tree = Cache::remember(
            MenuItem::cacheKeyFor($location),
            3600,
            fn () => MenuItem::query()
                ->where(MenuItem::LOCATION, $location)
                ->whereNull(MenuItem::PARENT_ID)
                ->where(MenuItem::IS_ACTIVE, true)
                ->with(['children' => fn ($q) => $activeOrdered($q)
                    ->with(['children' => $activeOrdered]),
                ])
                ->orderBy(MenuItem::SORT_ORDER)
                ->orderBy(MenuItem::ID)
                ->get()
        );

        return success(MenuItemResource::collection($tree), __('content.menu_received'));
    }

    // GET /pages/{slug}
    public function page(string $slug): JsonResponse
    {
        $pages = Cache::remember(Page::CACHE_KEY, 3600, fn () => Page::query()
            ->where(Page::IS_ACTIVE, true)
            ->get());

        $page = $pages->firstWhere(Page::SLUG, $slug);

        if (! $page) {
            return error(__('content.page_not_found'), [], 404);
        }

        return success(new PageResource($page), __('content.page_received'));
    }

    // GET /settings/public — فقط کلیدهای صراحتاً عمومی (config settings.public_keys)
    public function publicSettings(): JsonResponse
    {
        $publicKeys = config('settings.public_keys', []);

        $values = array_intersect_key(Setting::allCached(), array_flip($publicKeys));

        return success($values, __('content.settings_received'));
    }

    /**
     * GET /pages/{page}/blocks — بلوک‌های فعالِ یک صفحه‌ی محتوایی.
     *
     * کش‌شده و با `PageBlock::booted()` روی هر ذخیره فلاش می‌شود، پس ویرایشِ
     * ادمین بلافاصله روی سایت دیده می‌شود.
     */
    public function pageBlocks(string $page): JsonResponse
    {
        abort_unless(in_array($page, PageBlock::PAGES, true), 404);

        $blocks = Cache::remember(
            PageBlock::cacheKeyFor($page),
            3600,
            fn () => PageBlock::query()
                ->where(PageBlock::PAGE, $page)
                ->where(PageBlock::IS_ACTIVE, true)
                ->orderBy(PageBlock::SORT_ORDER)
                ->orderBy(PageBlock::ID)
                ->get()
        );

        return success(PageBlockResource::collection($blocks), __('page_blocks.list_received'));
    }
}
