<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Exceptions\BusinessException;
use App\Http\Requests\Product\ProductSpecRules;
use App\Http\Requests\Supplier\ProductProposalRequest;
use App\Models\Factory;
use App\Models\FactoryStock;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductVideo;
use App\Models\User;
use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * صفِ تغییرِ کاتالوگ از سوی تأمین‌کننده — ثبتِ پیشنهاد، تصمیمِ ادمین، اعمال.
 *
 * ## قاعده
 *
 * محصول و واریانت **سراسری**اند: یک ردیف را همه‌ی تأمین‌کننده‌ها می‌فروشند و
 * فقط قیمت و موجودی به کارخانه بسته‌اند (و آن دو مسیرِ خودشان را دارند). پس
 * تأمین‌کننده می‌تواند برای کالایی که **تأمین می‌کند** (قیمت یا موجودی از
 * کارخانه‌ی خودش دارد) ویرایش پیشنهاد دهد، یا کالای تازه پیشنهاد دهد — و هر
 * پیشنهادی، سبک یا سنگین، **تا تأییدِ ادمین** به کاتالوگ نمی‌رسد. اگر روزی
 * قاعده سبک‌تر شد، `TYPES`/فیلدهای درخواست تنها کلیدِ تغییر است.
 *
 * ## چرا اعمال از `ProductCatalogWriter` می‌گذرد
 *
 * همان مسیری که ادمین خودش محصول می‌سازد. یک کپیِ دوم از `Product::create`
 * این‌جا یعنی هر قاعده‌ای که بعداً به مسیرِ ادمین اضافه شود در پذیرش جا بماند.
 */
final class ProductChangeRequestService
{
    public function __construct(private readonly ProductCatalogWriter $writer) {}

    // ── مالکیت ────────────────────────────────────────────────────────────

    /**
     * محصولاتی که این تأمین‌کننده تأمین می‌کند: دستِ‌کم یک واریانتشان از یکی از
     * کارخانه‌های او قیمت یا موجودی دارد — **یا** خودِ محصول از پیشنهادِ تأییدشده‌ی
     * همین تأمین‌کننده ساخته شده. بدونِ شرطِ دوم، کسی که کالایی را پیشنهاد داده
     * تا وقتی برایش قیمت نگذاشته نمی‌توانست حتی یک غلطِ تایپی‌اش را اصلاح کند.
     *
     * @return Builder<Product>
     */
    public function suppliedProducts(int $supplierUserId): Builder
    {
        $factoryIds = Factory::query()
            ->select(Factory::ID)
            ->where(Factory::SUPPLIER_USER_ID, $supplierUserId);

        return Product::query()->where(function (Builder $products) use ($factoryIds, $supplierUserId) {
            $products
                ->whereHas('variants', function (Builder $variants) use ($factoryIds, $supplierUserId) {
                    $variants
                        ->whereHas('prices.factory', fn (Builder $f) => $f->where(Factory::SUPPLIER_USER_ID, $supplierUserId))
                        ->orWhereIn(
                            ProductVariant::ID,
                            FactoryStock::query()
                                ->select(FactoryStock::PRODUCT_VARIANT_ID)
                                ->whereIn(FactoryStock::FACTORY_ID, $factoryIds)
                        );
                })
                ->orWhereIn(
                    Product::ID,
                    ProductChangeRequest::query()
                        ->select(ProductChangeRequest::PRODUCT_ID)
                        ->where(ProductChangeRequest::SUPPLIER_USER_ID, $supplierUserId)
                        ->where(ProductChangeRequest::TYPE, ProductChangeRequest::TYPE_CREATE_PRODUCT)
                        ->where(ProductChangeRequest::STATUS, ProductChangeRequest::STATUS_APPROVED)
                        ->whereNotNull(ProductChangeRequest::PRODUCT_ID)
                );
        });
    }

    public function supplies(int $supplierUserId, Product $product): bool
    {
        return $this->suppliedProducts($supplierUserId)->whereKey($product->id)->exists();
    }

    /** ۴۰۳ اگر تأمین‌کننده این محصول را تأمین نمی‌کند. */
    public function assertSupplies(int $supplierUserId, Product $product): void
    {
        if (! $this->supplies($supplierUserId, $product)) {
            throw new BusinessException(__('product_changes.not_supplied'), 403);
        }
    }

    // ── ثبتِ پیشنهاد ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  خروجیِ `ProductProposalRequest::validated()`
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, UploadedFile>  $videos
     */
    public function proposeProduct(User $supplier, array $data, array $images = [], array $videos = []): ProductChangeRequest
    {
        $payload = $this->productPayload($data);
        $payload['variants'] = array_map(fn (array $v) => $this->variantPayload($v), $data['variants'] ?? []);
        $payload += $this->storeUploads($images, isset($data['primary_image_index']) ? (int) $data['primary_image_index'] : null, $videos, $data['video_titles'] ?? []);

        return ProductChangeRequest::create([
            ProductChangeRequest::SUPPLIER_USER_ID => $supplier->id,
            ProductChangeRequest::TYPE => ProductChangeRequest::TYPE_CREATE_PRODUCT,
            ProductChangeRequest::PAYLOAD => $payload,
            ProductChangeRequest::NOTE => $data['note'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, UploadedFile>  $videos
     */
    public function proposeProductUpdate(User $supplier, Product $product, array $data, array $images = [], array $videos = []): ProductChangeRequest
    {
        $this->assertSupplies($supplier->id, $product);

        $payload = $this->productPayload($data);
        $payload += $this->storeUploads($images, isset($data['primary_image_index']) ? (int) $data['primary_image_index'] : null, $videos, $data['video_titles'] ?? []);
        $payload['remove_image_ids'] = array_values(array_map('intval', $data['remove_image_ids'] ?? []));
        $payload['remove_video_ids'] = array_values(array_map('intval', $data['remove_video_ids'] ?? []));

        $product->loadMissing(['orderUnits', 'images', 'videos']);

        $before = $product->only(array_keys(Arr::except($payload, ['order_units', 'images', 'videos', 'remove_image_ids', 'remove_video_ids'])));
        $before['order_units'] = $product->orderUnits
            ->map(fn ($u) => ['order_unit_id' => $u->id, 'max_quantity' => (int) $u->pivot->max_quantity])
            ->values()->all();

        $diff = $this->diff($before, Arr::except($payload, ['images', 'videos', 'remove_image_ids', 'remove_video_ids']));
        $diff += $this->mediaDiff($product, $payload);

        if ($diff === []) {
            throw new BusinessException(__('product_changes.no_changes'), 422);
        }

        return ProductChangeRequest::create([
            ProductChangeRequest::SUPPLIER_USER_ID => $supplier->id,
            ProductChangeRequest::TYPE => ProductChangeRequest::TYPE_UPDATE_PRODUCT,
            ProductChangeRequest::PRODUCT_ID => $product->id,
            ProductChangeRequest::PAYLOAD => $payload,
            ProductChangeRequest::DIFF => $diff,
            ProductChangeRequest::NOTE => $data['note'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $data  خروجیِ `VariantProposalRequest::validated()` */
    public function proposeVariant(User $supplier, Product $product, array $data): ProductChangeRequest
    {
        $this->assertSupplies($supplier->id, $product);

        return ProductChangeRequest::create([
            ProductChangeRequest::SUPPLIER_USER_ID => $supplier->id,
            ProductChangeRequest::TYPE => ProductChangeRequest::TYPE_CREATE_VARIANT,
            ProductChangeRequest::PRODUCT_ID => $product->id,
            ProductChangeRequest::PAYLOAD => $this->variantPayload($data),
            ProductChangeRequest::NOTE => $data['note'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function proposeVariantUpdate(User $supplier, ProductVariant $variant, array $data): ProductChangeRequest
    {
        $variant->loadMissing(['product', 'variantAttributes']);
        $this->assertSupplies($supplier->id, $variant->product);

        $payload = $this->variantPayload($data);

        $before = $variant->only(array_keys(Arr::except($payload, ['attributes'])));
        if (array_key_exists('attributes', $payload)) {
            $before['attributes'] = $variant->variantAttributes
                ->map(fn ($a) => ['attribute_id' => $a->id, 'attribute_value_id' => (int) $a->details->attribute_value_id])
                ->values()->all();
        }

        $diff = $this->diff($before, $payload);

        if ($diff === []) {
            throw new BusinessException(__('product_changes.no_changes'), 422);
        }

        return ProductChangeRequest::create([
            ProductChangeRequest::SUPPLIER_USER_ID => $supplier->id,
            ProductChangeRequest::TYPE => ProductChangeRequest::TYPE_UPDATE_VARIANT,
            ProductChangeRequest::PRODUCT_ID => $variant->product_id,
            ProductChangeRequest::PRODUCT_VARIANT_ID => $variant->id,
            ProductChangeRequest::PAYLOAD => $payload,
            ProductChangeRequest::DIFF => $diff,
            ProductChangeRequest::NOTE => $data['note'] ?? null,
        ]);
    }

    /** انصراف — فقط تا وقتی بررسی نشده. ردیف می‌ماند (سابقه)، وضعیت `withdrawn` می‌شود. */
    public function withdraw(ProductChangeRequest $request): void
    {
        if (! $request->isPending()) {
            throw new BusinessException(__('product_changes.already_decided'), 422);
        }

        $request->forceFill([
            ProductChangeRequest::STATUS => ProductChangeRequest::STATUS_WITHDRAWN,
            ProductChangeRequest::DECIDED_AT => now(),
        ])->save();
    }

    // ── تصمیمِ ادمین ──────────────────────────────────────────────────────

    /**
     * پذیرش = اعمالِ payload از مسیرِ ادمین + ثبتِ تصمیم، در یک تراکنش و زیرِ قفلِ
     * ردیف، تا دو ادمینِ هم‌زمان دو بار نسازند. payload پیش از اعمال دوباره
     * اعتبارسنجی می‌شود: اسلاگ/SKU ممکن است از زمانِ ثبت تا تأیید گرفته شده
     * باشد، و آن باید ۴۲۲ با پیامِ روشن بدهد نه ۵۰۰ از ایندکسِ یکتا.
     */
    public function approve(ProductChangeRequest $request, User $admin, ?string $note): ProductChangeRequest
    {
        $request = DB::transaction(function () use ($request, $admin, $note) {
            /** @var ProductChangeRequest $locked */
            $locked = ProductChangeRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new BusinessException(__('product_changes.already_decided'), 409);
            }

            $applied = $this->apply($locked);

            $locked->forceFill([
                ProductChangeRequest::STATUS => ProductChangeRequest::STATUS_APPROVED,
                ProductChangeRequest::ADMIN_ID => $admin->id,
                ProductChangeRequest::ADMIN_NOTE => $note,
                ProductChangeRequest::DECIDED_AT => now(),
                // درخواستِ ساخت، پس از اعمال به رکوردِ ساخته‌شده اشاره می‌کند.
                ProductChangeRequest::PRODUCT_ID => $applied instanceof Product ? $applied->id : $locked->{ProductChangeRequest::PRODUCT_ID},
                ProductChangeRequest::PRODUCT_VARIANT_ID => $applied instanceof ProductVariant ? $applied->id : $locked->{ProductChangeRequest::PRODUCT_VARIANT_ID},
            ])->save();

            return $locked;
        });

        $this->notify($request, true, $note);

        return $request;
    }

    public function reject(ProductChangeRequest $request, User $admin, string $note): ProductChangeRequest
    {
        $request = DB::transaction(function () use ($request, $admin, $note) {
            /** @var ProductChangeRequest $locked */
            $locked = ProductChangeRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new BusinessException(__('product_changes.already_decided'), 409);
            }

            $locked->forceFill([
                ProductChangeRequest::STATUS => ProductChangeRequest::STATUS_REJECTED,
                ProductChangeRequest::ADMIN_ID => $admin->id,
                ProductChangeRequest::ADMIN_NOTE => $note,
                ProductChangeRequest::DECIDED_AT => now(),
            ])->save();

            return $locked;
        });

        $this->notify($request, false, $note);

        return $request;
    }

    // ── اعمال ─────────────────────────────────────────────────────────────

    private function apply(ProductChangeRequest $request): Model
    {
        $payload = $request->{ProductChangeRequest::PAYLOAD} ?? [];
        $type = $request->{ProductChangeRequest::TYPE};

        return match ($type) {
            ProductChangeRequest::TYPE_CREATE_PRODUCT => $this->applyCreateProduct($payload),
            ProductChangeRequest::TYPE_UPDATE_PRODUCT => $this->applyUpdateProduct($this->targetProduct($request), $request->{ProductChangeRequest::DIFF} ?? [], $payload),
            ProductChangeRequest::TYPE_CREATE_VARIANT => $this->applyCreateVariant($this->targetProduct($request), $payload),
            ProductChangeRequest::TYPE_UPDATE_VARIANT => $this->applyUpdateVariant($this->targetVariant($request), $request->{ProductChangeRequest::DIFF} ?? []),
            default => throw new BusinessException(__('product_changes.unknown_type')),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function applyCreateProduct(array $payload): Product
    {
        $this->revalidate($payload, $this->productRules(null, false) + $this->variantsRules());

        $product = $this->writer->createProduct(Arr::only($payload, array_keys(ProductSpecRules::product())));

        if (isset($payload['order_units'])) {
            $this->writer->syncOrderUnits($product, $payload['order_units']);
        }

        foreach ($payload['variants'] ?? [] as $variantSpec) {
            $variant = $this->writer->createVariant($product, Arr::only($variantSpec, array_keys(ProductSpecRules::variant())));
            if (isset($variantSpec['attributes'])) {
                $this->writer->syncVariantAttributes($variant, $variantSpec['attributes']);
            }
        }

        $this->applyMedia($product, $payload);

        return $product;
    }

    /**
     * فقط فیلدهای `diff` اعمال می‌شوند، نه کلِ payload: اگر ادمین بینِ ثبت و تأیید
     * فیلدِ دیگری را عوض کرده باشد، پذیرشِ این درخواست نباید آن را بازنویسی کند.
     *
     * @param  array<string, array{before: mixed, after: mixed}>  $diff
     * @param  array<string, mixed>  $payload
     */
    private function applyUpdateProduct(Product $product, array $diff, array $payload): Product
    {
        $after = array_map(fn (array $d) => $d['after'], Arr::except($diff, ['images_added', 'images_removed', 'videos_added', 'videos_removed']));

        $this->revalidate($after, $this->productRules($product->id, true));

        $scalar = Arr::only($after, array_keys(ProductSpecRules::product()));
        if ($scalar !== []) {
            $this->writer->updateProduct($product, $scalar);
        }

        if (isset($after['order_units'])) {
            $this->writer->syncOrderUnits($product, $after['order_units']);
        }

        $this->applyMedia($product, $payload);

        return $product;
    }

    /** @param  array<string, mixed>  $payload */
    private function applyCreateVariant(Product $product, array $payload): ProductVariant
    {
        $this->revalidate($payload, $this->variantRules(null, false));

        $variant = $this->writer->createVariant($product, Arr::only($payload, array_keys(ProductSpecRules::variant())));

        if (isset($payload['attributes'])) {
            $this->writer->syncVariantAttributes($variant, $payload['attributes']);
        }

        return $variant;
    }

    /** @param  array<string, array{before: mixed, after: mixed}>  $diff */
    private function applyUpdateVariant(ProductVariant $variant, array $diff): ProductVariant
    {
        $after = array_map(fn (array $d) => $d['after'], $diff);

        $this->revalidate($after, $this->variantRules($variant->id, true));

        $scalar = Arr::only($after, array_keys(ProductSpecRules::variant()));
        if ($scalar !== []) {
            $this->writer->updateVariant($variant, $scalar);
        }

        if (isset($after['attributes'])) {
            $this->writer->syncVariantAttributes($variant, $after['attributes']);
        }

        return $variant;
    }

    /**
     * فایل‌های درخواست به پوشه‌ی دائمیِ محصول منتقل و از همان مسیرِ ادمین متصل
     * می‌شوند. فایلِ گم‌شده سکوت نمی‌کند — تأییدی که «تصویر دارد» ولی تصویری
     * نمی‌سازد دروغ است.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyMedia(Product $product, array $payload): void
    {
        $disk = Storage::disk('public');

        foreach ($payload['remove_image_ids'] ?? [] as $id) {
            $image = $product->images()->whereKey($id)->first();
            if ($image instanceof ProductImage) {
                $this->writer->deleteImage($image);
            }
        }

        foreach ($payload['remove_video_ids'] ?? [] as $id) {
            $video = $product->videos()->whereKey($id)->first();
            if ($video instanceof ProductVideo) {
                $this->writer->deleteVideo($video);
            }
        }

        foreach ($payload['images'] ?? [] as $image) {
            $target = ProductCatalogWriter::IMAGE_DIR.'/'.basename($image['path']);
            if (! $disk->exists($image['path']) || ! $disk->move($image['path'], $target)) {
                throw new BusinessException(__('product_changes.media_missing'), 422);
            }
            $this->writer->attachImage($product, $target, (bool) ($image['is_primary'] ?? false), (int) ($image['sort_order'] ?? 0));
        }

        foreach ($payload['videos'] ?? [] as $video) {
            $target = ProductCatalogWriter::VIDEO_DIR.'/'.basename($video['path']);
            if (! $disk->exists($video['path']) || ! $disk->move($video['path'], $target)) {
                throw new BusinessException(__('product_changes.media_missing'), 422);
            }
            $this->writer->attachVideo($product, $target, null, $video['title'] ?? null, (int) ($video['sort_order'] ?? 0));
        }
    }

    private function targetProduct(ProductChangeRequest $request): Product
    {
        $product = $request->product;

        if (! $product instanceof Product) {
            throw new BusinessException(__('product_changes.target_missing'), 422);
        }

        return $product;
    }

    private function targetVariant(ProductChangeRequest $request): ProductVariant
    {
        $variant = $request->variant;

        if (! $variant instanceof ProductVariant) {
            throw new BusinessException(__('product_changes.target_missing'), 422);
        }

        return $variant;
    }

    // ── اعتبارسنجیِ دوباره در لحظه‌ی تأیید ───────────────────────────────

    /** @param  array<string, mixed>  $data */
    private function revalidate(array $data, array $rules): void
    {
        Validator::make($data, $rules)->validate();
    }

    /** @return array<string, array<int, mixed>> */
    private function productRules(?int $ignoreId, bool $partial): array
    {
        return Arr::except(ProductSpecRules::product($ignoreId, $partial), ProductProposalRequest::ADMIN_ONLY_PRODUCT)
            + ProductSpecRules::orderUnits('order_units', partial: true);
    }

    /** @return array<string, array<int, mixed>> */
    private function variantRules(?int $ignoreId, bool $partial, string $prefix = ''): array
    {
        return Arr::except(
            ProductSpecRules::variant($ignoreId, $partial, $prefix),
            array_map(fn (string $f) => $prefix.$f, ProductProposalRequest::ADMIN_ONLY_VARIANT),
        ) + ProductSpecRules::attributes($prefix.'attributes', partial: true);
    }

    /** @return array<string, array<int, mixed>> */
    private function variantsRules(): array
    {
        $rules = ['variants' => ['sometimes', 'array'], 'variants.*' => ['array']]
            + $this->variantRules(null, false, 'variants.*.');
        $rules['variants.*.'.ProductVariant::SKU][] = 'distinct';

        return $rules;
    }

    // ── payload و diff ────────────────────────────────────────────────────

    /**
     * فقط ستون‌های محصول + واحدها؛ فایل‌ها و کلیدهای کمکی جداگانه اضافه می‌شوند.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function productPayload(array $data): array
    {
        $payload = Arr::only($data, array_keys(ProductSpecRules::product()));
        if (array_key_exists('order_units', $data)) {
            $payload['order_units'] = array_values(array_map(fn (array $u) => [
                'order_unit_id' => (int) $u['order_unit_id'],
                'max_quantity' => (int) $u['max_quantity'],
            ], $data['order_units']));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function variantPayload(array $data): array
    {
        $payload = Arr::only($data, array_keys(ProductSpecRules::variant()));
        if (array_key_exists('attributes', $data)) {
            $payload['attributes'] = array_values(array_map(fn (array $p) => [
                'attribute_id' => (int) $p['attribute_id'],
                'attribute_value_id' => (int) $p['attribute_value_id'],
            ], $data['attributes']));
        }

        return $payload;
    }

    /**
     * فقط فیلدهایی که واقعاً عوض شده‌اند، با مقدارِ قبل و بعد. مقایسه پس از
     * نرمال‌سازی است: ستونِ decimal رشته می‌آید («5000.00») و ورودیِ فرم «5000»؛
     * بدونِ نرمال‌سازی هر ذخیره‌ای «تغییر» حساب می‌شد.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $diff = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ($this->normalize($key, $previous) !== $this->normalize($key, $value)) {
                $diff[$key] = ['before' => $this->presentable($previous), 'after' => $value];
            }
        }

        return $diff;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function mediaDiff(Product $product, array $payload): array
    {
        $diff = [];
        $url = fn (string $path) => Storage::disk('public')->url($path);

        if (($payload['images'] ?? []) !== []) {
            $diff['images_added'] = ['before' => null, 'after' => array_map(fn ($i) => $url($i['path']), $payload['images'])];
        }
        if (($payload['remove_image_ids'] ?? []) !== []) {
            $removed = $product->images->whereIn('id', $payload['remove_image_ids'])->map(fn ($i) => $url($i->image_path))->values()->all();
            $diff['images_removed'] = ['before' => $removed, 'after' => null];
        }
        if (($payload['videos'] ?? []) !== []) {
            $diff['videos_added'] = ['before' => null, 'after' => array_map(fn ($v) => $url($v['path']), $payload['videos'])];
        }
        if (($payload['remove_video_ids'] ?? []) !== []) {
            $removed = $product->videos->whereIn('id', $payload['remove_video_ids'])->map(fn ($v) => $url($v->video_path))->values()->all();
            $diff['videos_removed'] = ['before' => $removed, 'after' => null];
        }

        return $diff;
    }

    private function normalize(string $key, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }
        if (str_ends_with($key, '_date') && is_string($value)) {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        }
        if (is_numeric($value)) {
            return (string) (float) $value;
        }
        if (is_array($value)) {
            $rows = array_map(fn ($row) => is_array($row) ? json_encode(array_map(fn ($v) => is_numeric($v) ? (string) (float) $v : $v, $row)) : $row, $value);
            sort($rows);

            return $rows;
        }

        return is_string($value) ? trim($value) : $value;
    }

    /** مقدارِ «قبل» باید JSON‌پذیر باشد — Carbon به رشته. */
    private function presentable(mixed $value): mixed
    {
        return $value instanceof Carbon ? $value->format('Y-m-d H:i:s') : $value;
    }

    // ── فایل‌ها ───────────────────────────────────────────────────────────

    /**
     * فایل‌ها تا تصمیمِ ادمین زیرِ پوشه‌ی مخصوصِ درخواست می‌نشینند و با تأیید به
     * پوشه‌ی محصول منتقل می‌شوند (`applyMedia`).
     *
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, UploadedFile>  $videos
     * @param  array<int, string|null>  $titles
     * @return array{images: array<int, array<string, mixed>>, videos: array<int, array<string, mixed>>}
     */
    private function storeUploads(array $images, ?int $primaryIndex, array $videos, array $titles): array
    {
        // ⚠️ صداکننده‌ها `(int)` می‌کنند: multipart همه‌چیز را رشته می‌دهد («0») و
        // `validated()` قاعده‌ی `integer` را cast نمی‌کند — روی ۵۰۰ِ زنده پیدا شد.

        $dir = ProductChangeRequest::UPLOAD_DIR.'/'.Str::uuid();
        $out = ['images' => [], 'videos' => []];

        foreach (array_values($images) as $i => $file) {
            $out['images'][] = [
                'path' => $file->store($dir, 'public'),
                'is_primary' => $primaryIndex !== null ? $i === $primaryIndex : $i === 0,
                'sort_order' => $i,
            ];
        }

        foreach (array_values($videos) as $i => $file) {
            $out['videos'][] = [
                'path' => $file->store($dir, 'public'),
                'title' => $titles[$i] ?? null,
                'sort_order' => $i,
            ];
        }

        return $out;
    }

    // ── اعلان ─────────────────────────────────────────────────────────────

    /** پس از commit — یک شکستِ پیامک نباید تصمیمِ ثبت‌شده را برگرداند. */
    private function notify(ProductChangeRequest $request, bool $approved, ?string $note): void
    {
        $supplier = $request->supplier;

        if (! $supplier instanceof User) {
            return;
        }

        $subject = $this->subjectOf($request);

        try {
            if ($approved) {
                Notifier::toUser(
                    $supplier,
                    __('notifications.product_change_approved_subject'),
                    __('sms.product_change_approved', ['subject' => $subject]),
                );
            } else {
                Notifier::toUser(
                    $supplier,
                    __('notifications.product_change_rejected_subject'),
                    __('sms.product_change_rejected', ['subject' => $subject, 'reason' => $note ?? '']),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('product change request notification failed', [
                'request_id' => $request->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** نامِ چیزی که درخواست درباره‌اش است — برای متنِ پیامک. */
    public function subjectOf(ProductChangeRequest $request): string
    {
        $payload = $request->{ProductChangeRequest::PAYLOAD} ?? [];

        return (string) ($request->variant?->name
            ?? $request->product?->name
            ?? $payload['name']
            ?? '');
    }
}
