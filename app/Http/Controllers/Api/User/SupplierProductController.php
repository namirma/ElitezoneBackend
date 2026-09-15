<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\ProductProposalRequest;
use App\Http\Requests\Supplier\UpdateProductProposalRequest;
use App\Http\Requests\Supplier\UpdateVariantProposalRequest;
use App\Http\Requests\Supplier\VariantProposalRequest;
use App\Http\Resources\Order\OrderUnitResource;
use App\Http\Resources\Product\AttributeTypeResource;
use App\Http\Resources\Product\ProductCategoryResource;
use App\Http\Resources\Product\ProductChangeRequestResource;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\ProductVariantResource;
use App\Http\Resources\Product\ProductVideoResource;
use App\Models\AttributeType;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChangeRequest;
use App\Models\ProductVariant;
use App\Services\Product\ProductChangeRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * کاتالوگ از نگاهِ تأمین‌کننده — خواندنِ کالاهایی که تأمین می‌کند، و **پیشنهادِ**
 * ساخت/ویرایشِ محصول و واریانت.
 *
 * ⚠️ هیچ متدِ این کنترلر کاتالوگ را نمی‌نویسد. محصول و واریانت بینِ همه‌ی
 * تأمین‌کننده‌ها مشترک‌اند؛ هر پیشنهادی یک `ProductChangeRequest` می‌شود و
 * ادمین با پذیرشش از مسیرِ خودِ ادمین می‌نویسد (`ProductChangeRequestService`).
 * قیمت و موجودی — که مالِ خودِ تأمین‌کننده‌اند — کنترلرهای خودشان را دارند.
 */
class SupplierProductController extends Controller
{
    public function __construct(private readonly ProductChangeRequestService $service) {}

    /** کالاهایی که این تأمین‌کننده تأمین می‌کند، با تعدادِ واریانت و درخواستِ بازِ هرکدام. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = $this->service->suppliedProducts((int) Auth::id())
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn (Builder $w) => $w->where(Product::NAME, 'like', $term)->orWhere(Product::SKU, 'like', $term));
            })
            ->with('category')
            ->withCount(['variants', 'pendingChangeRequests'])
            ->latest(Product::ID)
            ->paginate($this->perPage(30));

        return success(ProductResource::collection($products), __('product_changes.list_received'));
    }

    /**
     * مشخصاتِ کاملِ یک کالا برای فرمِ ویرایش — همان ریسورس‌های ادمین/سایت، به‌علاوه‌ی
     * درخواست‌های بازِ همین کالا تا پنل بتواند «در انتظار تأیید» را نشان دهد.
     */
    public function show(Product $product): JsonResponse
    {
        $this->service->assertSupplies((int) Auth::id(), $product);

        $product->load(['category', 'images', 'videos', 'orderUnits'])->loadCount('variants');

        $variants = $product->variants()
            ->with(['variantAttributes.attributeType', 'limitOrderUnit'])
            ->orderBy(ProductVariant::NAME)
            ->get();

        $pending = ProductChangeRequest::query()
            ->pending()
            ->where(ProductChangeRequest::SUPPLIER_USER_ID, Auth::id())
            ->where(ProductChangeRequest::PRODUCT_ID, $product->id)
            ->with(['product', 'variant'])
            ->latest(ProductChangeRequest::ID)
            ->get();

        return success([
            'product' => new ProductResource($product),
            'variants' => ProductVariantResource::collection($variants),
            'videos' => ProductVideoResource::collection($product->videos),
            'pending_requests' => ProductChangeRequestResource::collection($pending),
        ], __('product_changes.received'));
    }

    /**
     * فهرست‌های انتخابیِ فرم — دسته‌ها (با انواعِ ویژگیِ هر دسته)، واحدهای سفارش،
     * و انواعِ ویژگی با ویژگی‌ها و مقادیرشان. یک فراخوان، چون هر سه فقط وقتی لازم‌اند
     * که فرم باز است و هیچ‌کدام اندپوینتِ عمومی ندارند.
     */
    public function options(): JsonResponse
    {
        return success([
            'categories' => ProductCategoryResource::collection(
                ProductCategory::query()->with('attributeTypes')->orderBy(ProductCategory::NAME)->get()
            ),
            'order_units' => OrderUnitResource::collection(
                OrderUnit::query()->orderByDesc(OrderUnit::AMOUNT)->get()
            ),
            'attribute_types' => AttributeTypeResource::collection(
                AttributeType::query()->with('attributes.values')->orderBy(AttributeType::NAME)->get()
            ),
        ], __('product_changes.received'));
    }

    // ── پیشنهادها ─────────────────────────────────────────────────────────

    public function store(ProductProposalRequest $request): JsonResponse
    {
        $proposal = $this->service->proposeProduct(
            $request->user(),
            $request->validated(),
            $request->file('images', []),
            $request->file('videos', []),
        );

        return created(new ProductChangeRequestResource($proposal), __('product_changes.submitted'));
    }

    public function update(UpdateProductProposalRequest $request, Product $product): JsonResponse
    {
        $proposal = $this->service->proposeProductUpdate(
            $request->user(),
            $product,
            $request->validated(),
            $request->file('images', []),
            $request->file('videos', []),
        );

        return created(new ProductChangeRequestResource($proposal->load('product')), __('product_changes.submitted'));
    }

    public function storeVariant(VariantProposalRequest $request, Product $product): JsonResponse
    {
        $proposal = $this->service->proposeVariant($request->user(), $product, $request->validated());

        return created(new ProductChangeRequestResource($proposal->load('product')), __('product_changes.submitted'));
    }

    public function updateVariant(UpdateVariantProposalRequest $request, ProductVariant $variant): JsonResponse
    {
        $proposal = $this->service->proposeVariantUpdate($request->user(), $variant, $request->validated());

        return created(new ProductChangeRequestResource($proposal->load(['product', 'variant'])), __('product_changes.submitted'));
    }

    // ── درخواست‌های من ────────────────────────────────────────────────────

    public function requests(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(ProductChangeRequest::STATUSES)],
            'type' => ['nullable', Rule::in(ProductChangeRequest::TYPES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = ProductChangeRequest::query()
            ->where(ProductChangeRequest::SUPPLIER_USER_ID, Auth::id())
            ->when($request->filled('status'), fn (Builder $q) => $q->where(ProductChangeRequest::STATUS, $request->input('status')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where(ProductChangeRequest::TYPE, $request->input('type')))
            ->with(['product', 'variant'])
            ->latest(ProductChangeRequest::ID)
            ->paginate($this->perPage(20));

        return success(ProductChangeRequestResource::collection($requests), __('product_changes.list_received'));
    }

    /** انصراف از درخواستِ هنوز بررسی‌نشده. */
    public function withdraw(ProductChangeRequest $changeRequest): JsonResponse
    {
        if ((int) $changeRequest->{ProductChangeRequest::SUPPLIER_USER_ID} !== (int) Auth::id()) {
            return error(__('global.forbidden'), [], 403);
        }

        $this->service->withdraw($changeRequest);

        return success(new ProductChangeRequestResource($changeRequest->load(['product', 'variant'])), __('product_changes.withdrawn'));
    }
}
