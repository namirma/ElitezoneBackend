<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageRequest;
use App\Http\Requests\Admin\UpdatePageRequest;
use App\Http\Resources\Content\PageResource;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD صفحات استاتیک (ماژول ۱۰ نیازمندی فرانت BackOffice).
 */
class AdminPageController extends Controller
{
    // GET /admin/pages
    public function index(Request $request): JsonResponse
    {
        $query = Page::query()->orderByDesc(Page::ID);

        if ($request->has('is_active')) {
            $query->where(Page::IS_ACTIVE, $request->boolean('is_active'));
        }

        return success(PageResource::collection($query->paginate($this->perPage(50))), __('admin.pages.list_received'));
    }

    // GET /admin/pages/{page}
    public function show(Page $page): JsonResponse
    {
        return success(new PageResource($page), __('admin.pages.received'));
    }

    // POST /admin/pages
    public function store(StorePageRequest $request): JsonResponse
    {
        $page = Page::create($request->validated());

        return created(new PageResource($page), __('admin.pages.created'));
    }

    // PUT /admin/pages/{page}
    public function update(UpdatePageRequest $request, Page $page): JsonResponse
    {
        $page->update($request->validated());

        return success(new PageResource($page->refresh()), __('admin.pages.updated'));
    }

    // POST /admin/pages/{page}/toggle
    public function toggle(Page $page): JsonResponse
    {
        $page->update([Page::IS_ACTIVE => ! $page->{Page::IS_ACTIVE}]);

        return success(new PageResource($page->refresh()), __('admin.pages.toggled'));
    }

    // DELETE /admin/pages/{page}
    public function destroy(Page $page): JsonResponse
    {
        $page->delete();

        return success(null, __('admin.pages.deleted'));
    }
}
