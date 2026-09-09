<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\BulkPriceRequest;
use App\Http\Resources\Product\ProductPriceResource;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ProductPrice;
use App\Services\Supplier\PriceChangeApprovalGate;
use App\Services\Supplier\SupplierOriginGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پنل خودسرویس سوپلایر — مدیریت قیمت محصولات، فقط برای کارخانه/انبارهای خودش.
 *
 * قیمت به سه‌تایی (واریانت × کارخانه × انبار) گره خورده و یکتاست — دقیقاً مثل
 * AdminProductPriceController؛ کش قیمت پایه به‌طور خودکار توسط ProductPrice::booted()
 * فلاش می‌شود. فرق اصلی با نسخه‌ی ادمین: factory_id/factory_location_id باید هر دو
 * به سوپلایر جاری تعلق داشته باشند و با هم سازگار باشند (location واقعاً زیرِ همان
 * factory)، وگرنه یک سوپلایر می‌توانست زیر کارخانه‌ی سوپلایر دیگری قیمت ثبت کند.
 * محصول/واریانت را خودِ سوپلایر نمی‌سازد — کاتالوگ محصولات ادمین‌محور باقی می‌ماند؛
 * سوپلایر فقط برای واریانت‌های موجود، در کارخانه‌ی خودش قیمت تعیین می‌کند.
 */
class SupplierProductPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'product_variant_id' => ['nullable', 'integer'],
            'factory_id' => ['nullable', 'integer'],
            'factory_location_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $prices = ProductPrice::query()
            ->whereHas('factory', fn ($q) => $q->where(Factory::SUPPLIER_USER_ID, Auth::id()))
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where(ProductPrice::PRODUCT_VARIANT_ID, $request->integer('product_variant_id')))
            ->when($request->filled('factory_id'), fn ($q) => $q->where(ProductPrice::FACTORY_ID, $request->integer('factory_id')))
            ->when($request->filled('factory_location_id'), fn ($q) => $q->where(ProductPrice::FACTORY_LOCATION_ID, $request->integer('factory_location_id')))
            ->with(['factory', 'factoryLocation'])
            ->latest(ProductPrice::ID)
            ->paginate($this->perPage(50));

        return success(ProductPriceResource::collection($prices), __('admin.prices.list_received'));
    }

    /**
     * چکِ تکراری‌بودن و درج، هر دو باید یک تصمیم باشند.
     *
     * قبلاً `exists()` و بعد `create()` بدون هیچ قفلی اجرا می‌شد. دو درخواستِ
     * هم‌زمان هر دو از چک رد می‌شدند و دومی به ایندکسِ یکتای
     * `unique_product_price` می‌خورد — یعنی به‌جای ۴۲۲ِ موردنظر، یک
     * `QueryException` مدیریت‌نشده و پاسخِ ۵۰۰. حالا خودِ نقضِ قید هم به همان
     * ۴۲۲ نگاشت می‌شود، پس مسابقه و تکرارِ ساده یک پاسخ می‌گیرند.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->storeRules());

        $exists = ProductPrice::query()
            ->where(ProductPrice::PRODUCT_VARIANT_ID, $data[ProductPrice::PRODUCT_VARIANT_ID])
            ->where(ProductPrice::FACTORY_ID, $data[ProductPrice::FACTORY_ID])
            ->where(ProductPrice::FACTORY_LOCATION_ID, $data[ProductPrice::FACTORY_LOCATION_ID])
            ->exists();

        if ($exists) {
            return error(__('admin.prices.duplicate'), [], 422);
        }

        $data[ProductPrice::LAST_UPDATED_AT] = now();

        try {
            $price = ProductPrice::create($data);
        } catch (UniqueConstraintViolationException) {
            return error(__('admin.prices.duplicate'), [], 422);
        }

        return created(new ProductPriceResource($price->load(['factory', 'factoryLocation'])), __('admin.prices.created'));
    }

    /**
     * POST /profile/supplier/prices/bulk — آپلودِ گروهی.
     *
     * تا امروز هر قیمت یک POST بود. برای تأمین‌کننده‌ای با ده‌ها واریانت × چند
     * مبدأ این سقفِ عملیِ پنل بود: به‌روزرسانیِ یک فهرستِ قیمت یعنی صدها
     * درخواست، و هر ردیفِ تکراری هم ۴۲۲ می‌گرفت.
     *
     * سه تصمیم:
     *
     * ۱. **upsert است نه insert.** فهرستِ قیمت را دوباره می‌فرستند تا به‌روز
     *    شود؛ اجبار به دانستنِ اینکه کدام ردیف از قبل هست، خودِ کار را
     *    غیرممکن می‌کرد.
     * ۲. **همه یا هیچ.** فهرستی که نیمه اعمال شود بدتر از ردشده است — کاربر
     *    نمی‌داند کدام سطر نشست و کدام نه، و قیمتِ نیمه‌به‌روز مستقیم روی
     *    فروش می‌نشیند.
     * ۳. **خطا به‌ازای شماره‌ی ردیف.** «یکی از ردیف‌ها غلط است» در فایلِ
     *    ۳۰۰ سطری بی‌فایده است.
     */
    public function bulkStore(BulkPriceRequest $request, SupplierOriginGuard $guard, PriceChangeApprovalGate $gate): JsonResponse
    {
        $rows = $request->validated('rows');

        $errors = $guard->validate($rows, (int) Auth::id());

        if ($errors !== []) {
            return error(__('supplier.bulk.rows_rejected'), ['rows' => $errors], 422);
        }

        $supplierId = (int) Auth::id();

        $summary = DB::transaction(function () use ($rows, $gate, $supplierId) {
            $created = 0;
            $updated = 0;
            $queued = 0;

            foreach ($rows as $row) {
                $identity = [
                    ProductPrice::PRODUCT_VARIANT_ID => $row['product_variant_id'],
                    ProductPrice::FACTORY_ID => $row['factory_id'],
                    ProductPrice::FACTORY_LOCATION_ID => $row['factory_location_id'],
                ];

                $existing = ProductPrice::query()->where($identity)->lockForUpdate()->first();

                // نه `?->` : larastan روی برنچِ L13 `$existing` را non-nullable
                // می‌بیند و nullsafe را «بی‌مورد» خطا می‌گیرد. شرطِ صریح روی هر
                // دو برنچ یکسان می‌گذرد.
                $keepSpecial = $existing !== null ? $existing->{ProductPrice::SPECIAL_PRICE} : 0;
                $keepGolden = $existing !== null ? $existing->{ProductPrice::GOLDEN_FIGURE} : 0;

                $values = [
                    ProductPrice::GENERAL_PRICE => $row['general_price'],
                    ProductPrice::SPECIAL_PRICE => $row['special_price'] ?? $keepSpecial,
                    ProductPrice::GOLDEN_FIGURE => $row['golden_figure'] ?? $keepGolden,
                    ProductPrice::LAST_UPDATED_AT => now(),
                ];

                if ($existing) {
                    // همان دروازه‌ی `update()`. بدونِ این، ارسالِ یک تغییرِ بزرگ
                    // به‌صورت یک ردیفِ bulk کاملاً از تأییدِ ادمین رد می‌شد.
                    $pending = $gate->evaluate($existing, $values);

                    if ($pending !== null) {
                        $gate->queue($supplierId, $pending, __('supplier.prices.change_reason'));
                        $queued++;

                        continue;
                    }

                    $existing->update($values);
                    $updated++;
                } else {
                    // ردیفِ تازه آستانه ندارد: قیمتِ قبلی‌ای وجود ندارد که نسبت
                    // به آن درصدی معنا داشته باشد.
                    ProductPrice::create($identity + $values);
                    $created++;
                }
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'queued_for_approval' => $queued,
                'total' => $created + $updated,
            ];
        });

        return success($summary, __('supplier.bulk.prices_saved'));
    }

    /** Update only mutates the price values — the (variant,factory,location) triple is the row identity. */
    public function update(Request $request, ProductPrice $price, PriceChangeApprovalGate $gate): JsonResponse
    {
        $this->authorize('update', $price);

        $data = $request->validate($this->updateRules());

        // تغییرِ بزرگ اعمال نمی‌شود؛ به صفِ تأییدِ ادمین می‌رود (تصمیم مدیریت).
        // ⚠️ همین بررسی در `bulkStore` هم هست — اگر فقط این‌جا بود، فرستادنِ همان
        // تغییر به‌صورت یک ردیفِ bulk کاملاً دورش می‌زد.
        $queued = $gate->evaluate($price, $data);

        if ($queued !== null) {
            $gate->queue((int) Auth::id(), $queued, __('supplier.prices.change_reason'));

            return success([
                'queued_for_approval' => true,
                'change_percent' => $queued['change_percent'],
            ], __('supplier.prices.queued_for_approval'));
        }

        $data[ProductPrice::LAST_UPDATED_AT] = now();
        $price->update($data);

        return success(new ProductPriceResource($price->load(['factory', 'factoryLocation'])), __('admin.prices.updated'));
    }

    public function destroy(ProductPrice $price): JsonResponse
    {
        $this->authorize('delete', $price);

        $price->delete();

        return success(null, __('admin.prices.deleted'));
    }

    /** Create needs the full identity triple + prices; factory/location must both belong to me and match each other. */
    private function storeRules(): array
    {
        return [
            ProductPrice::PRODUCT_VARIANT_ID => ['required', 'integer', 'exists:product_variants,id'],
            ProductPrice::FACTORY_ID => [
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
            ProductPrice::FACTORY_LOCATION_ID => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $location = FactoryLocation::find($value);

                    if (! $location || (int) $location->factory?->{Factory::SUPPLIER_USER_ID} !== (int) Auth::id()) {
                        $fail(__('auth.policy.factory_access_denied'));

                        return;
                    }

                    if ((int) $location->{FactoryLocation::FACTORY_ID} !== (int) request()->integer(ProductPrice::FACTORY_ID)) {
                        $fail(__('admin.prices.location_factory_mismatch'));
                    }
                },
            ],
            ProductPrice::GENERAL_PRICE => ['required', 'numeric', 'min:0'],
            ProductPrice::SPECIAL_PRICE => ['required', 'numeric', 'min:0'],
            ProductPrice::GOLDEN_FIGURE => ['required', 'numeric', 'min:0'],
        ];
    }

    private function updateRules(): array
    {
        return [
            ProductPrice::GENERAL_PRICE => ['sometimes', 'required', 'numeric', 'min:0'],
            ProductPrice::SPECIAL_PRICE => ['sometimes', 'required', 'numeric', 'min:0'],
            ProductPrice::GOLDEN_FIGURE => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }
}
