<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\FactoryStockResource;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * موجودیِ به‌ازای مبدأ — نمای ادمین (بدون محدودیتِ مالکیت).
 *
 * چرا لازم است: از وقتی `factory_stocks` مرجعِ سفارش شد، عددِ اشتباهِ یک
 * تأمین‌کننده بدون این کنترلر فقط با tinker قابلِ اصلاح بود. ادمین هم باید
 * بتواند ببیند و درست کند — همان‌طور که برای قیمت می‌تواند.
 */
class AdminFactoryStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'product_variant_id' => ['nullable', 'integer'],
            'factory_id' => ['nullable', 'integer'],
            'factory_location_id' => ['nullable', 'integer'],
            'supplier_user_id' => ['nullable', 'integer'],
            'only_out_of_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $stocks = FactoryStock::query()
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where(FactoryStock::PRODUCT_VARIANT_ID, $request->integer('product_variant_id')))
            ->when($request->filled('factory_id'), fn ($q) => $q->where(FactoryStock::FACTORY_ID, $request->integer('factory_id')))
            ->when($request->filled('factory_location_id'), fn ($q) => $q->where(FactoryStock::FACTORY_LOCATION_ID, $request->integer('factory_location_id')))
            ->when(
                $request->filled('supplier_user_id'),
                fn ($q) => $q->whereHas('originFactory', fn ($f) => $f->where(Factory::SUPPLIER_USER_ID, $request->integer('supplier_user_id')))
            )
            ->when($request->boolean('only_out_of_stock'), fn ($q) => $q->where(FactoryStock::QUANTITY, '<=', 0))
            ->with(['productVariant', 'originFactory', 'originFactoryLocation'])
            ->orderBy(FactoryStock::FACTORY_ID)
            ->orderBy(FactoryStock::FACTORY_LOCATION_ID)
            ->orderBy(FactoryStock::PRODUCT_VARIANT_ID)
            ->paginate($this->perPage(50));

        return success(FactoryStockResource::collection($stocks), __('admin.stocks.list_received'));
    }

    /** upsert — همان قرارداد نسخه‌ی تأمین‌کننده، بدون گاردِ مالکیت. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            FactoryStock::PRODUCT_VARIANT_ID => ['required', 'integer', 'exists:product_variants,id'],
            FactoryStock::FACTORY_ID => ['required', 'integer', 'exists:factories,id'],
            FactoryStock::FACTORY_LOCATION_ID => [
                'required',
                'integer',
                'exists:factory_locations,id',
                function ($attribute, $value, $fail) {
                    $location = FactoryLocation::find($value);

                    if ($location && (int) $location->{FactoryLocation::FACTORY_ID} !== (int) request()->integer(FactoryStock::FACTORY_ID)) {
                        $fail(__('admin.prices.location_factory_mismatch'));
                    }
                },
            ],
            FactoryStock::QUANTITY => ['required', 'numeric', 'min:0'],
        ]);

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

    public function update(Request $request, FactoryStock $stock): JsonResponse
    {
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

    public function destroy(FactoryStock $stock): JsonResponse
    {
        $variantId = (int) $stock->{FactoryStock::PRODUCT_VARIANT_ID};

        DB::transaction(function () use ($stock, $variantId) {
            $stock->delete();
            FactoryStock::syncVariantRollup($variantId);
        });

        return success(null, __('admin.stocks.deleted'));
    }
}
