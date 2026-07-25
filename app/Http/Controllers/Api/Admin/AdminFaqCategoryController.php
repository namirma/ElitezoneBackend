<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqCategoryRequest;
use App\Http\Requests\Admin\UpdateFaqCategoryRequest;
use App\Http\Resources\Content\FaqCategoryResource;
use App\Models\FaqCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD دسته‌بندی سوالات متداول.
 *
 * منوی کشوی پشتیبانیِ سایت اصلی مستقیماً از روی همین دسته‌ها ساخته می‌شود
 * (عنوان + زیرعنوان + ترتیب)، پس ترتیبِ `sort_order` واقعاً روی UI اثر دارد.
 */
class AdminFaqCategoryController extends Controller
{
    // GET /admin/faq-categories
    public function index(Request $request): JsonResponse
    {
        $query = FaqCategory::query()
            ->withCount('faqs')
            ->orderBy(FaqCategory::SORT_ORDER)
            ->orderBy(FaqCategory::ID);

        if ($request->has('is_active')) {
            $query->where(FaqCategory::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($request->filled('q')) {
            $query->where(FaqCategory::TITLE, 'like', '%'.$request->string('q').'%');
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return success(
            FaqCategoryResource::collection($query->paginate($this->perPage(50))),
            __('admin.faq_categories.list_received')
        );
    }

    // GET /admin/faq-categories/{faq_category}
    public function show(FaqCategory $faqCategory): JsonResponse
    {
        return success(new FaqCategoryResource($faqCategory->loadCount('faqs')), __('admin.faq_categories.received'));
    }

    // POST /admin/faq-categories
    public function store(StoreFaqCategoryRequest $request): JsonResponse
    {
        $category = FaqCategory::create($request->validated());

        return created(new FaqCategoryResource($category), __('admin.faq_categories.created'));
    }

    // PUT /admin/faq-categories/{faq_category}
    public function update(UpdateFaqCategoryRequest $request, FaqCategory $faqCategory): JsonResponse
    {
        $faqCategory->update($request->validated());

        return success(new FaqCategoryResource($faqCategory->refresh()), __('admin.faq_categories.updated'));
    }

    // POST /admin/faq-categories/{faq_category}/toggle
    public function toggle(FaqCategory $faqCategory): JsonResponse
    {
        $faqCategory->update([FaqCategory::IS_ACTIVE => ! $faqCategory->{FaqCategory::IS_ACTIVE}]);

        return success(new FaqCategoryResource($faqCategory->refresh()), __('admin.faq_categories.toggled'));
    }

    /**
     * DELETE /admin/faq-categories/{faq_category} — حذف نرم.
     *
     * دسته‌ای که هنوز سوال دارد حذف نمی‌شود: حذفش آن سوال‌ها را بی‌دسته می‌کرد و
     * از منوی پشتیبانیِ سایت ناپدید می‌شدند بدون اینکه ادمین متوجه شود.
     */
    public function destroy(FaqCategory $faqCategory): JsonResponse
    {
        if ($faqCategory->faqs()->exists()) {
            return error(__('admin.faq_categories.has_faqs'), [], 422);
        }

        $faqCategory->delete();

        return success(null, __('admin.faq_categories.deleted'));
    }

    // POST /admin/faq-categories/{id}/restore
    public function restore(int $id): JsonResponse
    {
        $category = FaqCategory::onlyTrashed()->findOrFail($id);
        $category->restore();

        return success(new FaqCategoryResource($category->refresh()), __('admin.faq_categories.restored'));
    }
}
