<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Resources\Content\FaqResource;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD سؤالات متداول (ماژول ۳ نیازمندی فرانت BackOffice).
 */
class AdminFaqController extends Controller
{
    // GET /admin/faq
    public function index(Request $request): JsonResponse
    {
        $query = Faq::query()
            ->orderBy(Faq::SORT_ORDER)
            ->orderBy(Faq::ID);

        if ($request->has('is_active')) {
            $query->where(Faq::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($request->filled('category')) {
            $query->where(Faq::CATEGORY, $request->string('category'));
        }

        return success(FaqResource::collection($query->paginate($this->perPage(50))), __('admin.faq.list_received'));
    }

    // GET /admin/faq/{faq}
    public function show(Faq $faq): JsonResponse
    {
        return success(new FaqResource($faq), __('admin.faq.received'));
    }

    // POST /admin/faq
    public function store(StoreFaqRequest $request): JsonResponse
    {
        $faq = Faq::create($request->validated());

        return created(new FaqResource($faq), __('admin.faq.created'));
    }

    // PUT /admin/faq/{faq}
    public function update(UpdateFaqRequest $request, Faq $faq): JsonResponse
    {
        $faq->update($request->validated());

        return success(new FaqResource($faq->refresh()), __('admin.faq.updated'));
    }

    // POST /admin/faq/{faq}/toggle
    public function toggle(Faq $faq): JsonResponse
    {
        $faq->update([Faq::IS_ACTIVE => ! $faq->{Faq::IS_ACTIVE}]);

        return success(new FaqResource($faq->refresh()), __('admin.faq.toggled'));
    }

    // DELETE /admin/faq/{faq}
    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return success(null, __('admin.faq.deleted'));
    }
}
