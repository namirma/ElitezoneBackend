<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBannerRequest;
use App\Http\Requests\Admin\UpdateBannerRequest;
use App\Http\Resources\Content\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * پنل مدیریت — CRUD بنرها (ماژول ۱ نیازمندی فرانت BackOffice).
 *
 * تصویر روی دیسک public ذخیره و توسط BannerResource به صورت URL کامل سرو می‌شود.
 * آپلود/تعویض فایل همیشه با POST/multipart (نه PUT خام).
 */
class AdminBannerController extends Controller
{
    // GET /admin/banners
    public function index(Request $request): JsonResponse
    {
        $query = Banner::query()
            ->orderBy(Banner::SORT_ORDER)
            ->orderByDesc(Banner::ID);

        if ($request->filled('position')) {
            $query->where(Banner::POSITION, $request->string('position'));
        }

        if ($request->has('is_active')) {
            $query->where(Banner::IS_ACTIVE, $request->boolean('is_active'));
        }

        // سطل بازیافت: ?trashed=1 فقط حذف‌شده‌های نرم را برمی‌گرداند
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return success(BannerResource::collection($query->paginate($this->perPage())), __('admin.banners.list_received'));
    }

    // GET /admin/banners/{banner}
    public function show(Banner $banner): JsonResponse
    {
        return success(new BannerResource($banner), __('admin.banners.received'));
    }

    // POST /admin/banners
    public function store(StoreBannerRequest $request): JsonResponse
    {
        $data = $this->withCategoryScopedToPosition($request->safe()->except(Banner::IMAGE), $request->input(Banner::POSITION));
        $data[Banner::IMAGE] = $request->file(Banner::IMAGE)->store('banners', 'public');

        $banner = Banner::create($data);

        return created(new BannerResource($banner), __('admin.banners.created'));
    }

    // PUT|POST /admin/banners/{banner}
    public function update(UpdateBannerRequest $request, Banner $banner): JsonResponse
    {
        $data = $this->withCategoryScopedToPosition(
            $request->safe()->except(Banner::IMAGE),
            $request->input(Banner::POSITION, $banner->{Banner::POSITION})
        );

        if ($request->hasFile(Banner::IMAGE)) {
            $this->deleteImageIfOwned($banner);
            $data[Banner::IMAGE] = $request->file(Banner::IMAGE)->store('banners', 'public');
        }

        $banner->update($data);

        return success(new BannerResource($banner->refresh()), __('admin.banners.updated'));
    }

    // POST /admin/banners/{banner}/toggle
    public function toggle(Banner $banner): JsonResponse
    {
        $banner->update([Banner::IS_ACTIVE => ! $banner->{Banner::IS_ACTIVE}]);

        return success(new BannerResource($banner->refresh()), __('admin.banners.toggled'));
    }

    // DELETE /admin/banners/{banner} — حذف نرم (فایل تصویر تا restore حفظ می‌شود)
    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();

        return success(null, __('admin.banners.deleted'));
    }

    // POST /admin/banners/{id}/restore
    public function restore(int $id): JsonResponse
    {
        $banner = Banner::onlyTrashed()->findOrFail($id);
        $banner->restore();

        return success(new BannerResource($banner->refresh()), __('admin.banners.restored'));
    }

    /**
     * `category_id` فقط روی جایگاهِ «بنر دسته‌بندی» معنی دارد. ادمینی که جایگاه را
     * از دسته‌بندی به هوم عوض می‌کند نباید دسته‌ی قبلی را با خودش بکشد — وگرنه
     * صفحه‌ی دسته‌بندی بنری را می‌بیند که دیگر مالِ او نیست.
     */
    private function withCategoryScopedToPosition(array $data, ?string $position): array
    {
        if ($position !== Banner::POSITION_CATEGORY) {
            $data[Banner::CATEGORY_ID] = null;
        }

        return $data;
    }

    private function deleteImageIfOwned(Banner $banner): void
    {
        $image = $banner->{Banner::IMAGE};

        if ($image && ! str_starts_with($image, 'http') && Storage::disk('public')->exists($image)) {
            Storage::disk('public')->delete($image);
        }
    }
}
