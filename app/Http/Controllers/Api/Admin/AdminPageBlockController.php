<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageBlockRequest;
use App\Http\Requests\Admin\UpdatePageBlockRequest;
use App\Http\Resources\Content\PageBlockResource;
use App\Models\PageBlock;
use App\Services\Content\PageBlockSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminPageBlockController extends Controller
{
    /**
     * `types` تنها چیزی است که پیش‌تر برمی‌گشت. `shapes` تعریفِ همان اعضایی است
     * که یک فیلدِ `list` با `of` به آن‌ها اشاره می‌کند (مثلِ `card_grid.cards` →
     * `of: 'card'`) — بدونِ آن، بک‌آفیس نامِ شکل را می‌داند ولی نمی‌داند شکل چه
     * کلیدهایی دارد و نمی‌تواند زیرفرمش را بسازد. دو کلیدِ هم‌سطح (نه تودرتو زیرِ
     * هر فیلد) چون یک شکل را چند نوع/فیلدِ متفاوت می‌توانند به اشتراک بگذارند
     * (مثلاً `card` هم زیرِ `card_grid` است)؛ تکرارِ آن در هر فیلد یعنی چند کپی
     * از یک شکل در یک پاسخ، دقیقاً همان مشکلی که SHAPES/all() برای خودِ نوع‌ها
     * حل کرده‌اند.
     */
    /**
     * `site_url` این‌جاست چون آدرسِ سایت یک واقعیتِ **زمانِ‌اجرا**ست، نه یک
     * ثابتِ زمانِ‌بیلد.
     *
     * پیش از این بک‌آفیس آن را از `VITE_SITE_ORIGIN` خودش می‌خواند، که داخلِ
     * باندل کامپایل می‌شد؛ یعنی انتشارِ سایت روی دامنه‌ی واقعی، **بیلدِ دوباره‌ی
     * پنل** لازم داشت — و تا آن لحظه پیش‌نمایش به `localhost:5173` اشاره
     * می‌کرد. بک‌اند از قبل این را به‌عنوان `FRONT_APP_URL` می‌شناسد (همان
     * چیزی که ایمیل‌های تراکنشی هم با آن ساخته می‌شوند)، پس همان یک مقدار
     * منبعِ حقیقتِ مشترک است: عوض‌کردنِ یک متغیرِ محیطی در بک‌اند کافی است.
     */
    public function schema(PageBlockSchema $schema): JsonResponse
    {
        return success([
            'types' => $schema->all(),
            'shapes' => $this->normalizedShapes(),
            'site_url' => rtrim((string) config('app.front_url'), '/') ?: null,
        ], __('page_blocks.schema_received'));
    }

    /**
     * اعضای شکل‌ها همان‌طور که هستند سرو می‌شوند: `{type, label, options?}` —
     * دقیقاً همان قالبی که فیلدهای سطحِ بالای `types[*].fields` دارند، پس
     * بک‌آفیس یک ویجت‌سازِ واحد دارد و مجبور نیست حدس بزند کدام قالب را می‌بیند.
     *
     * تا پیش از افزودنِ لیبل‌ها، عضوِ شکل می‌توانست رشته‌ی خالیِ نامِ ویجت باشد و
     * این متد آن حالت را هم نرمال می‌کرد. حالا هر عضو ناچار آرایه است (جایی
     * برای `label` لازم داشت)، پس آن شاخه مرده شد و PHPStan درست گرفتش.
     *
     * ⚠️ `options` **فهرستِ رشته نیست** — جفت‌های `{value, label}` است، چون
     * بک‌آفیس هم مقدارِ ذخیره‌شده را لازم دارد هم متنِ فارسیِ نمایشی. اعلانِ قبلی
     * `list<string>` می‌گفت و روی L10 (PHPStan 1) بی‌صدا رد می‌شد؛ larastan-3
     * روی L13 گرفتش. داده درست بود و اعلان غلط، پس اعلان اصلاح شد نه داده.
     *
     * `hint` و `of` هم اختیاری‌اند و در اعلانِ قبلی اصلاً نبودند (`of` را
     * `bodyStrong` دارد: نوعِ عضوِ فهرست).
     *
     * @return array<string, array<string, array{
     *     type: string,
     *     label: string,
     *     hint?: string,
     *     of?: string,
     *     options?: list<array{value: string, label: string}>
     * }>>
     */
    private function normalizedShapes(): array
    {
        return PageBlockSchema::SHAPES;
    }

    public function index(Request $request): JsonResponse
    {
        $query = PageBlock::query()
            ->orderBy(PageBlock::SORT_ORDER)
            ->orderBy(PageBlock::ID);

        if ($request->filled('page')) {
            $query->where(PageBlock::PAGE, $request->string('page'));
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return success(PageBlockResource::collection($query->get()), __('page_blocks.list_received'));
    }

    public function show(PageBlock $pageBlock): JsonResponse
    {
        return success(new PageBlockResource($pageBlock), __('page_blocks.received'));
    }

    public function store(StorePageBlockRequest $request): JsonResponse
    {
        $block = PageBlock::create($request->validated());

        return created(new PageBlockResource($block), __('page_blocks.created'));
    }

    public function update(UpdatePageBlockRequest $request, PageBlock $pageBlock): JsonResponse
    {
        $pageBlock->update($request->validated());

        return success(new PageBlockResource($pageBlock->fresh()), __('page_blocks.updated'));
    }

    public function destroy(PageBlock $pageBlock): JsonResponse
    {
        $pageBlock->delete();

        return success(null, __('page_blocks.deleted'));
    }

    /**
     * فقط ردیف‌هایی که فرستاده شده‌اند به‌روز می‌شوند.
     *
     * همان قراردادِ «منوی سایت»: پنل دیفِ خودش را می‌فرستد، نه کلِ صفحه را —
     * پس دو ادمین که هم‌زمان دو بلوکِ متفاوت را جابه‌جا می‌کنند کارِ هم را
     * پاک نمی‌کنند.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            // `exists` یک کوئریِ خامِ دیتابیس است و اسکوپِ SoftDeletes مدل را
            // نمی‌بیند، پس بدونِ `withoutTrashed()` ردیفِ حذف‌شده هم قبول
            // می‌شد — و بعد `whereKey()->update()` پایین (که همان اسکوپ را
            // *دارد*) بی‌صدا هیچ ردیفی را عوض نمی‌کرد: یک ۲۰۰ که کاری نکرده.
            'items.*.id' => ['required', 'integer', Rule::exists(PageBlock::TABLE, PageBlock::ID)->withoutTrashed()],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['items'] as $item) {
            PageBlock::whereKey($item['id'])->update([PageBlock::SORT_ORDER => $item['sort_order']]);
        }

        // `update()` روی کوئری‌بیلدر رویدادِ مدل نمی‌زند، پس کش خودش فلاش نمی‌شود.
        foreach (PageBlock::PAGES as $page) {
            Cache::forget(PageBlock::cacheKeyFor($page));
        }

        return success(null, __('page_blocks.reordered'));
    }

    public function toggle(PageBlock $pageBlock): JsonResponse
    {
        $pageBlock->update([PageBlock::IS_ACTIVE => ! $pageBlock->{PageBlock::IS_ACTIVE}]);

        return success(new PageBlockResource($pageBlock->fresh()), __('page_blocks.toggled'));
    }

    public function restore(int $id): JsonResponse
    {
        $block = PageBlock::onlyTrashed()->findOrFail($id);
        $block->restore();

        return success(new PageBlockResource($block), __('page_blocks.restored'));
    }

    /**
     * یک فایل، یک پاسخ. عمداً از بدنه‌ی خودِ بلوک جداست: یک `card_grid` می‌تواند
     * شش عکس داشته باشد و multipart با JSONِ تودرتو آن را به قراردادی شکننده
     * تبدیل می‌کند. `data` فقط رشته‌ی `path` را نگه می‌دارد.
     */
    public function media(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $path = $request->file('file')->store('page-blocks', 'public');

        return success([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], __('page_blocks.media_uploaded'));
    }
}
