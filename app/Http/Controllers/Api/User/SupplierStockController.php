<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\BulkStockRequest;
use App\Http\Resources\Supplier\FactoryStockResource;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Services\Supplier\SupplierOriginGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پنل خودسرویس تأمین‌کننده — موجودی، به‌ازای کارخانه/انبارِ خودش.
 *
 * تا پیش از ساختِ `factory_stocks` این پنل **نمی‌توانست** وجود داشته باشد:
 * موجودی یک ستونِ سراسری روی هر واریانت بود، پس دادنِ حقِ نوشتن به تأمین‌کننده
 * یعنی اجازه‌ی صفر کردنِ موجودیِ تأمین‌کننده‌ی دیگر. حالا کلید همان سه‌تاییِ
 * `product_prices` است و هر کس فقط مبادیِ خودش را می‌بیند و می‌نویسد.
 *
 * دقیقاً مثل `SupplierProductPriceController`، مالکیت هرگز از ورودی خوانده
 * نمی‌شود: `factory_id` و `factory_location_id` با closure rule بررسی می‌شوند
 * که هم مالِ خودِ کاربر باشند و هم با هم سازگار.
 */
class SupplierStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'product_variant_id' => ['nullable', 'integer'],
            'factory_id' => ['nullable', 'integer'],
            'factory_location_id' => ['nullable', 'integer'],
            'only_out_of_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $stocks = FactoryStock::query()
            ->whereHas('originFactory', fn ($q) => $q->where(Factory::SUPPLIER_USER_ID, Auth::id()))
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where(FactoryStock::PRODUCT_VARIANT_ID, $request->integer('product_variant_id')))
            ->when($request->filled('factory_id'), fn ($q) => $q->where(FactoryStock::FACTORY_ID, $request->integer('factory_id')))
            ->when($request->filled('factory_location_id'), fn ($q) => $q->where(FactoryStock::FACTORY_LOCATION_ID, $request->integer('factory_location_id')))
            ->when($request->boolean('only_out_of_stock'), fn ($q) => $q->where(FactoryStock::QUANTITY, '<=', 0))
            ->with(['productVariant', 'originFactory', 'originFactoryLocation'])
            ->orderBy(FactoryStock::FACTORY_ID)
            ->orderBy(FactoryStock::FACTORY_LOCATION_ID)
            ->orderBy(FactoryStock::PRODUCT_VARIANT_ID)
            ->paginate($this->perPage(50));

        return success(FactoryStockResource::collection($stocks), __('admin.stocks.list_received'));
    }

    /**
     * ثبت یا به‌روزرسانیِ موجودیِ یک سه‌تایی (upsert).
     *
     * upsert است نه create: «موجودیِ میلگرد ۱۴ در انبارِ اصفهان» یک واقعیتِ
     * یکتاست، و مجبور کردنِ کاربر به اینکه اول بداند ردیف هست یا نه، فقط او را
     * وادار به گرفتنِ ۴۲۲ و تلاش دوباره می‌کرد.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $stock = DB::transaction(function () use ($data) {
            $stock = FactoryStock::query()
                ->where(FactoryStock::PRODUCT_VARIANT_ID, $data[FactoryStock::PRODUCT_VARIANT_ID])
                ->where(FactoryStock::FACTORY_ID, $data[FactoryStock::FACTORY_ID])
                ->where(FactoryStock::FACTORY_LOCATION_ID, $data[FactoryStock::FACTORY_LOCATION_ID])
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->update([FactoryStock::QUANTITY => $data[FactoryStock::QUANTITY]]);
            } else {
                $stock = FactoryStock::create($data);
            }

            FactoryStock::syncVariantRollup((int) $data[FactoryStock::PRODUCT_VARIANT_ID]);

            return $stock;
        });

        return success(
            new FactoryStockResource($stock->refresh()->load(['productVariant', 'originFactory', 'originFactoryLocation'])),
            __('admin.stocks.saved')
        );
    }

    /**
     * POST /profile/supplier/stocks/bulk — قرینه‌ی آپلودِ گروهیِ قیمت.
     *
     * همان سه تصمیم: upsert، همه‌یا‌هیچ، و خطا به‌ازای شماره‌ی ردیف.
     *
     * ‏`syncVariantRollup()` **یک‌بار به‌ازای هر واریانت** صدا زده می‌شود نه هر
     * ردیف: رول‌آپ مجموعِ همه‌ی مبدأهای آن واریانت است، پس اجرای دوباره‌اش برای
     * ردیفِ دومِ همان واریانت فقط همان عدد را دوباره حساب می‌کند.
     */
    public function bulkStore(BulkStockRequest $request, SupplierOriginGuard $guard): JsonResponse
    {
        $rows = $request->validated('rows');

        $errors = $guard->validate($rows, (int) Auth::id());

        if ($errors !== []) {
            return error(__('supplier.bulk.rows_rejected'), ['rows' => $errors], 422);
        }

        $summary = DB::transaction(function () use ($rows) {
            $created = 0;
            $updated = 0;
            $variantIds = [];

            foreach ($rows as $row) {
                $identity = [
                    FactoryStock::PRODUCT_VARIANT_ID => $row['product_variant_id'],
                    FactoryStock::FACTORY_ID => $row['factory_id'],
                    FactoryStock::FACTORY_LOCATION_ID => $row['factory_location_id'],
                ];

                $existing = FactoryStock::query()->where($identity)->lockForUpdate()->first();

                if ($existing) {
                    $existing->update([FactoryStock::QUANTITY => $row['quantity']]);
                    $updated++;
                } else {
                    FactoryStock::create($identity + [FactoryStock::QUANTITY => $row['quantity']]);
                    $created++;
                }

                $variantIds[(int) $row['product_variant_id']] = true;
            }

            foreach (array_keys($variantIds) as $variantId) {
                FactoryStock::syncVariantRollup($variantId);
            }

            return ['created' => $created, 'updated' => $updated, 'total' => $created + $updated];
        });

        return success($summary, __('supplier.bulk.stocks_saved'));
    }

    /** فقط مقدار عوض می‌شود — سه‌تاییِ هویت، هویتِ ردیف است. */
    public function update(Request $request, FactoryStock $stock): JsonResponse
    {
        $this->authorize('update', $stock);

        $data = $request->validate([
            FactoryStock::QUANTITY => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($stock, $data) {
            $stock->update($data);
            FactoryStock::syncVariantRollup((int) $stock->{FactoryStock::PRODUCT_VARIANT_ID});
        });

        return success(
            new FactoryStockResource($stock->refresh()->load(['productVariant', 'originFactory', 'originFactoryLocation'])),
            __('admin.stocks.updated')
        );
    }

    /**
     * حذفِ ردیف = «این مبدأ دیگر موجودیِ اختصاصی ندارد».
     *
     * توجه: حذف، فروش را متوقف نمی‌کند — نبودِ ردیف یعنی برگشت به عددِ سراسری،
     * نه صفر. برای «موجود نیست»، مقدار را صفر بگذارید.
     */
    public function destroy(FactoryStock $stock): JsonResponse
    {
        $this->authorize('delete', $stock);

        $variantId = (int) $stock->{FactoryStock::PRODUCT_VARIANT_ID};

        DB::transaction(function () use ($stock, $variantId) {
            $stock->delete();
            FactoryStock::syncVariantRollup($variantId);
        });

        return success(null, __('admin.stocks.deleted'));
    }

    private function rules(): array
    {
        return [
            FactoryStock::PRODUCT_VARIANT_ID => ['required', 'integer', 'exists:product_variants,id'],
            FactoryStock::FACTORY_ID => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $owned = Factory::where(Factory::ID, $value)
                        ->where(Factory::SUPPLIER_USER_ID, Auth::id())
                        ->exists();

                    if (! $owned) {
                        $fail(__('auth.policy.factory_access_denied'));
                    }
                },
            ],
            FactoryStock::FACTORY_LOCATION_ID => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $location = FactoryLocation::find($value);

                    // نه `?->` روی رابطه: larastan روی برنچِ L13 آن را non-nullable
                    // می‌بیند و nullsafe را «بی‌مورد» خطا می‌گیرد. در عمل می‌تواند null
                    // باشد (انبارِ یتیم)، پس چکِ صریح می‌ماند — روی هر دو برنچ یکسان.
                    $owner = $location?->factory;

                    if (! $location || $owner === null || (int) $owner->{Factory::SUPPLIER_USER_ID} !== (int) Auth::id()) {
                        $fail(__('auth.policy.factory_access_denied'));

                        return;
                    }

                    if ((int) $location->{FactoryLocation::FACTORY_ID} !== (int) request()->integer(FactoryStock::FACTORY_ID)) {
                        $fail(__('admin.prices.location_factory_mismatch'));
                    }
                },
            ],
            FactoryStock::QUANTITY => ['required', 'numeric', 'min:0'],
        ];
    }
}
