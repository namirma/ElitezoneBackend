<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuItemRequest;
use App\Http\Requests\Admin\UpdateMenuItemRequest;
use App\Http\Resources\Content\MenuItemResource;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD منوی سایت (مگامنوی نوار بالای فرانت اصلی).
 *
 * لیست به‌صورت **تخت** برمی‌گردد (نه درختی) تا صفحه‌ی CRUD بک‌آفیس بتواند
 * مثل بقیه‌ی ماژول‌ها یک جدول ساده نشان دهد؛ ستون «والد» رابطه را روشن می‌کند.
 * نمای درختی مخصوص endpoint عمومی است که فرانت مصرف می‌کند.
 */
class AdminMenuItemController extends Controller
{
    // GET /admin/menu-items
    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::query()
            ->orderBy(MenuItem::LOCATION)
            ->orderBy(MenuItem::PARENT_ID)
            ->orderBy(MenuItem::SORT_ORDER)
            ->orderBy(MenuItem::ID);

        if ($request->filled('location')) {
            $query->where(MenuItem::LOCATION, $request->string('location'));
        }

        if ($request->has('is_active')) {
            $query->where(MenuItem::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($request->filled('parent_id')) {
            $query->where(MenuItem::PARENT_ID, $request->integer('parent_id'));
        }

        // فقط آیتم‌های اصلیِ نوار (برای پرکردن دراپ‌داون «والد» در فرم)
        if ($request->boolean('roots_only')) {
            $query->whereNull(MenuItem::PARENT_ID);
        }

        if ($request->filled('q')) {
            $query->where(MenuItem::TITLE, 'like', '%'.$request->string('q').'%');
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return success(
            MenuItemResource::collection($query->paginate($this->perPage(100))),
            __('admin.menu.list_received')
        );
    }

    // GET /admin/menu-items/{menu_item}
    public function show(MenuItem $menuItem): JsonResponse
    {
        return success(new MenuItemResource($menuItem), __('admin.menu.received'));
    }

    // POST /admin/menu-items
    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $item = MenuItem::create($request->validated());

        return created(new MenuItemResource($item), __('admin.menu.created'));
    }

    // PUT /admin/menu-items/{menu_item}
    public function update(UpdateMenuItemRequest $request, MenuItem $menuItem): JsonResponse
    {
        $menuItem->update($request->validated());

        return success(new MenuItemResource($menuItem->refresh()), __('admin.menu.updated'));
    }

    // POST /admin/menu-items/{menu_item}/toggle
    public function toggle(MenuItem $menuItem): JsonResponse
    {
        $menuItem->update([MenuItem::IS_ACTIVE => ! $menuItem->{MenuItem::IS_ACTIVE}]);

        return success(new MenuItemResource($menuItem->refresh()), __('admin.menu.toggled'));
    }

    /**
     * DELETE /admin/menu-items/{menu_item} — حذف نرم.
     *
     * آیتمی که زیرمجموعه دارد حذف نمی‌شود: چون FK روی cascadeOnDelete است، حذف
     * سخت کلِ شاخه را می‌برد و ادمین متوجه نمی‌شد چند آیتم از منو غیب شد.
     */
    public function destroy(MenuItem $menuItem): JsonResponse
    {
        if ($menuItem->children()->exists()) {
            return error(__('admin.menu.has_children'), [], 422);
        }

        $menuItem->delete();

        return success(null, __('admin.menu.deleted'));
    }

    // POST /admin/menu-items/{id}/restore
    public function restore(int $id): JsonResponse
    {
        $item = MenuItem::onlyTrashed()->findOrFail($id);
        $item->restore();

        return success(new MenuItemResource($item->refresh()), __('admin.menu.restored'));
    }
}
