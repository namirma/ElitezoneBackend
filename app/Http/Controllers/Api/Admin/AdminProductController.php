<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductImageResource;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\ProductVideoResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD محصولات + مدیریت رسانه (تصویر/ویدیو).
 *
 * محصول با cuid مسیر می‌خورد. حذف محصولِ استفاده‌شده در سفارش‌ها به‌خاطر FK ممکن نیست؛
 * در آن حالت پیام «غیرفعالش کن» برمی‌گردد (به‌جای ۵۰۰). فایل‌های رسانه روی دیسک public
 * ذخیره و هنگام حذف، فایل فیزیکی هم پاک می‌شود.
 */
class AdminProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $products = Product::query()
            ->when($request->filled('category_id'), fn ($q) => $q->where(Product::CATEGORY_ID, $request->integer('category_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where(Product::IS_ACTIVE, $request->boolean('is_active')))
            ->when($request->has('is_featured'), fn ($q) => $q->where(Product::IS_FEATURED, $request->boolean('is_featured')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where(Product::NAME, 'like', $term)->orWhere(Product::SKU, 'like', $term));
            })
            ->with('category')
            ->withCount('variants')
            ->latest(Product::ID)
            ->paginate($this->perPage(30));

        return success(ProductResource::collection($products), __('admin.products.list_received'));
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'images', 'videos', 'variants'])->loadCount('variants');

        return success(new ProductResource($product), __('admin.products.received'));
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($request->validate($this->rules()));

        return created(new ProductResource($product->load('category')), __('admin.products.created'));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product->update($request->validate($this->rules($product->id)));

        return success(new ProductResource($product->load('category')), __('admin.products.updated'));
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            DB::transaction(function () use ($product) {
                // remove uploaded media files before the DB cascade drops the rows
                foreach ($product->images as $image) {
                    $this->deleteFileIfOwned($image->image_path);
                }
                foreach ($product->videos as $video) {
                    $this->deleteFileIfOwned($video->video_path);
                    $this->deleteFileIfOwned($video->thumbnail_path);
                }
                $product->delete();
            });
        } catch (QueryException) {
            return error(__('admin.products.delete_blocked'), [], 422);
        }

        return success(null, __('admin.products.deleted'));
    }

    public function toggle(Product $product): JsonResponse
    {
        $product->update([Product::IS_ACTIVE => ! $product->{Product::IS_ACTIVE}]);

        $message = $product->{Product::IS_ACTIVE}
            ? __('admin.products.toggled_active')
            : __('admin.products.toggled_inactive');

        return success(new ProductResource($product), $message);
    }

    public function toggleFeatured(Product $product): JsonResponse
    {
        $product->update([Product::IS_FEATURED => ! $product->{Product::IS_FEATURED}]);

        return success(new ProductResource($product), __('admin.products.featured_updated'));
    }

    // ── Images (product_images table) ─────────────────────────────────────

    public function addImage(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            ProductImage::IMAGE_PATH => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            ProductImage::IS_PRIMARY => ['boolean'],
            ProductImage::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ]);

        $image = DB::transaction(function () use ($request, $product, $data) {
            $isPrimary = (bool) ($data[ProductImage::IS_PRIMARY] ?? false);
            if ($isPrimary) {
                $product->images()->update([ProductImage::IS_PRIMARY => false]);
            }

            return $product->images()->create([
                ProductImage::IMAGE_PATH => $request->file(ProductImage::IMAGE_PATH)->store('products', 'public'),
                ProductImage::IS_PRIMARY => $isPrimary,
                ProductImage::SORT_ORDER => $data[ProductImage::SORT_ORDER] ?? 0,
            ]);
        });

        return created(new ProductImageResource($image), __('admin.products.image_added'));
    }

    public function deleteImage(Product $product, ProductImage $image): JsonResponse
    {
        $this->assertBelongsToProduct((int) $image->product_id, $product->id);

        $this->deleteFileIfOwned($image->image_path);
        $image->delete();

        return success(null, __('admin.products.image_deleted'));
    }

    public function setPrimaryImage(Product $product, ProductImage $image): JsonResponse
    {
        $this->assertBelongsToProduct((int) $image->product_id, $product->id);

        DB::transaction(function () use ($product, $image) {
            $product->images()->update([ProductImage::IS_PRIMARY => false]);
            $image->update([ProductImage::IS_PRIMARY => true]);
        });

        return success(new ProductImageResource($image->refresh()), __('admin.products.primary_set'));
    }

    // ── Videos (product_videos table) ─────────────────────────────────────

    public function addVideo(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            ProductVideo::VIDEO_PATH => ['required', 'file', 'mimes:mp4,webm,mov,ogg', 'max:51200'],
            ProductVideo::THUMBNAIL_PATH => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            ProductVideo::TITLE => ['nullable', 'string', 'max:255'],
            ProductVideo::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ]);

        $video = $product->videos()->create([
            ProductVideo::VIDEO_PATH => $request->file(ProductVideo::VIDEO_PATH)->store('product-videos', 'public'),
            ProductVideo::THUMBNAIL_PATH => $request->hasFile(ProductVideo::THUMBNAIL_PATH)
                ? $request->file(ProductVideo::THUMBNAIL_PATH)->store('product-videos/thumbnails', 'public')
                : null,
            ProductVideo::TITLE => $request->input(ProductVideo::TITLE),
            ProductVideo::SORT_ORDER => $request->integer(ProductVideo::SORT_ORDER, 0),
        ]);

        return created(new ProductVideoResource($video), __('admin.products.video_added'));
    }

    public function deleteVideo(Product $product, ProductVideo $video): JsonResponse
    {
        $this->assertBelongsToProduct((int) $video->product_id, $product->id);

        $this->deleteFileIfOwned($video->video_path);
        $this->deleteFileIfOwned($video->thumbnail_path);
        $video->delete();

        return success(null, __('admin.products.video_deleted'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            Product::CATEGORY_ID => [$req, 'integer', 'exists:product_categories,id'],
            Product::NAME => [$req, 'string', 'max:255'],
            Product::SLUG => [$req, 'string', 'max:255', Rule::unique('products', 'slug')->ignore($ignoreId)],
            Product::SKU => [$req, 'string', 'max:255', Rule::unique('products', 'sku')->ignore($ignoreId)],
            Product::DESCRIPTION => ['nullable', 'string'],
            Product::SHIPPING_PROMO => ['nullable', 'string', 'max:255'],
            Product::IS_ACTIVE => ['boolean'],
            Product::IS_FEATURED => ['boolean'],
            Product::ITEMS_PER_PAGE => ['nullable', 'integer', 'min:1', 'max:100'],
            Product::AGGREGATE_MIN_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            Product::AGGREGATE_MAX_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            Product::AGGREGATE_LIMIT_ORDER_UNIT_ID => ['nullable', 'integer', 'exists:order_units,id'],
        ];
    }

    private function assertBelongsToProduct(int $ownerId, int $productId): void
    {
        abort_unless($ownerId === $productId, 404);
    }

    private function deleteFileIfOwned(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
