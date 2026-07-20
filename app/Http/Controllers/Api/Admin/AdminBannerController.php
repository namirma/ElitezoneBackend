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
        $data = $request->safe()->except(Banner::IMAGE);
        $data[Banner::IMAGE] = $request->file(Banner::IMAGE)->store('banners', 'public');

        $banner = Banner::create($data);

        return created(new BannerResource($banner), __('admin.banners.created'));
    }

    // PUT|POST /admin/banners/{banner}
    public function update(UpdateBannerRequest $request, Banner $banner): JsonResponse
    {
        $data = $request->safe()->except(Banner::IMAGE);

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

    // DELETE /admin/banners/{banner}
    public function destroy(Banner $banner): JsonResponse
    {
        $this->deleteImageIfOwned($banner);
        $banner->delete();

        return success(null, __('admin.banners.deleted'));
    }

    private function deleteImageIfOwned(Banner $banner): void
    {
        $image = $banner->{Banner::IMAGE};

        if ($image && ! str_starts_with($image, 'http') && Storage::disk('public')->exists($image)) {
            Storage::disk('public')->delete($image);
        }
    }
}
