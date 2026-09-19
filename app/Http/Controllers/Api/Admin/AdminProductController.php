<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductSpecRules;
use App\Http\Resources\Product\ProductImageResource;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\ProductVideoResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use App\Services\Product\ProductCatalogWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * پنل مدیریت — CRUD محصولات + مدیریت رسانه (تصویر/ویدیو).
 *
 * محصول با cuid مسیر می‌خورد. حذف محصولِ استفاده‌شده در سفارش‌ها به‌خاطر FK ممکن نیست؛
 * در آن حالت پیام «غیرفعالش کن» برمی‌گردد (به‌جای ۵۰۰). فایل‌های رسانه روی دیسک public
 * ذخیره و هنگام حذف، فایل فیزیکی هم پاک می‌شود.
 *
 * ⚠️ هر نوشتنی از `ProductCatalogWriter` می‌گذرد و قواعدش از `ProductSpecRules`
 * می‌آید — همان دو چیزی که پذیرشِ درخواستِ تأمین‌کننده هم استفاده می‌کند. این
 * کنترلر **نباید** دوباره `Product::create` یا قاعده‌ی محلی بگیرد، وگرنه دو
 * مسیرِ نوشتن با دو تعریف از «محصولِ معتبر» ساخته می‌شود.
 */
class AdminProductController extends Controller
{
    public function __construct(private readonly ProductCatalogWriter $writer) {}

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
        $product->load(['category', 'images', 'videos', 'variants', 'orderUnits'])->loadCount('variants');

        return success(new ProductResource($product), __('admin.products.received'));
    }

    /**
     * واحدهای سفارشِ مجازِ این محصول + سقف تعداد هرکدام (پیوت `product_order_units`).
     *
     * جایگزینیِ کامل است (`sync`) — همان قرارداد `syncAttributes` واریانت و
     * `syncFilters` دسته‌ی فیلتر قیمت.
     *
     * ⚠️ محصولی که هیچ ردیفی اینجا ندارد **قابل افزودن به سبد نیست**:
     * `AddToCartAction::validateOrderUnitCounts` هر واحدی را که در این پیوت نباشد
     * «واحد نامعتبر» می‌شمارد و ۴۲۲ می‌دهد. تا نشست ۲۱ این جدول هیچ راه مدیریتی
     * نداشت و فقط با seed پر می‌شد.
     */
    public function syncOrderUnits(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(ProductSpecRules::orderUnits('units'));

        $this->writer->syncOrderUnits($product, $data['units']);

        return success(
            new ProductResource($product->load(['category', 'orderUnits'])),
            __('admin.products.order_units_synced')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $product = $this->writer->createProduct($request->validate(ProductSpecRules::product()));

        return created(new ProductResource($product->load('category')), __('admin.products.created'));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->writer->updateProduct($product, $request->validate(ProductSpecRules::product($product->id, partial: true)));

        return success(new ProductResource($product->load('category')), __('admin.products.updated'));
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            DB::transaction(function () use ($product) {
                // remove uploaded media files before the DB cascade drops the rows
                foreach ($product->images as $image) {
                    $this->writer->deleteFileIfOwned($image->image_path);
                }
                foreach ($product->videos as $video) {
                    $this->writer->deleteFileIfOwned($video->video_path);
                    $this->writer->deleteFileIfOwned($video->thumbnail_path);
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
        $data = $request->validate(ProductSpecRules::imageUpload());

        $image = $this->writer->storeImage(
            $product,
            $request->file(ProductImage::IMAGE_PATH),
            (bool) ($data[ProductImage::IS_PRIMARY] ?? false),
            (int) ($data[ProductImage::SORT_ORDER] ?? 0),
        );

        return created(new ProductImageResource($image), __('admin.products.image_added'));
    }

    public function deleteImage(Product $product, ProductImage $image): JsonResponse
    {
        $this->assertBelongsToProduct((int) $image->product_id, $product->id);

        $this->writer->deleteImage($image);

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
        $request->validate(ProductSpecRules::videoUpload());

        $video = $this->writer->storeVideo(
            $product,
            $request->file(ProductVideo::VIDEO_PATH),
            $request->hasFile(ProductVideo::THUMBNAIL_PATH) ? $request->file(ProductVideo::THUMBNAIL_PATH) : null,
            $request->input(ProductVideo::TITLE),
            $request->integer(ProductVideo::SORT_ORDER, 0),
        );

        return created(new ProductVideoResource($video), __('admin.products.video_added'));
    }

    public function deleteVideo(Product $product, ProductVideo $video): JsonResponse
    {
        $this->assertBelongsToProduct((int) $video->product_id, $product->id);

        $this->writer->deleteVideo($video);

        return success(null, __('admin.products.video_deleted'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function assertBelongsToProduct(int $ownerId, int $productId): void
    {
        abort_unless($ownerId === $productId, 404);
    }
}
