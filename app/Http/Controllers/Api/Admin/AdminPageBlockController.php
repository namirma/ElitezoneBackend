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
    public function schema(PageBlockSchema $schema): JsonResponse
    {
        return success([
            'types' => $schema->all(),
            'shapes' => $this->normalizedShapes(),
        ], __('page_blocks.schema_received'));
    }

    /**
     * `PageBlockSchema::SHAPES` بدونِ لمس‌کردنِ خودِ ثابت، نرمال می‌شود: هر عضوِ
     * شکل، چه در ثابت رشته باشد (نامِ ویجت) چه آرایه (`select` با `options`)،
     * این‌جا همیشه آرایه‌ی `{type, options?}` می‌شود — دقیقاً همان قالبی که
     * فیلدهای سطحِ بالای `types[*].fields` از قبل دارند. بک‌آفیس این‌طور مجبور
     * نیست حدس بزند کدام حالت را می‌بیند. `SHAPES` عمداً دست‌نخورده می‌ماند:
     * `PageBlockSchema::listRules()` به شکلِ خامِ آن (رشته یا آرایه) وابسته است
     * و این نرمال‌سازی فقط برای پاسخِ همین اندپوینت است.
     *
     * @return array<string, array<string, array{type: string, options?: list<string>}>>
     */
    private function normalizedShapes(): array
    {
        return array_map(
            static fn (array $shape): array => array_map(
                static fn (string|array $member): array => is_array($member) ? $member : ['type' => $member],
                $shape,
            ),
            PageBlockSchema::SHAPES,
        );
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
            'items.*.id' => ['required', 'integer', 'exists:page_blocks,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['items'] as $item) {
            PageBlock::whereKey($item['id'])->update([PageBlock::SORT_ORDER => $item['sort_order']]);
        }

        // `update()` روی کوئری‌بیلدر رویدادِ مدل نمی‌زند، پس کش خودش فلاش نمی‌شود.
        foreach (PageBlock::PAGES as $page) {
            \Illuminate\Support\Facades\Cache::forget(PageBlock::cacheKeyFor($page));
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
            'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
        ], __('page_blocks.media_uploaded'));
    }
}
