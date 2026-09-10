<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\SupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * صفِ بررسیِ درخواست‌های تأمین‌کننده.
 *
 * ⚠️ **پذیرش اینجا هیچ کاری نمی‌کند جز ثبتِ تصمیم.**
 *
 * وسوسه‌ی طبیعی این است که `approve` روی یک درخواستِ لغو، خودش
 * `CancelOrderAction` را صدا بزند. عمداً نمی‌زند: بند ۳.۶ لغوِ اجباری را به
 * **تیکت و دلیل** مشروط کرده و `forceCancel` هر دو را الزامی می‌گیرد. اگر
 * پذیرشِ این صف هم لغو می‌کرد، یک مسیرِ دومِ لغو ساخته می‌شد که آن دو شرط را
 * ندارد — و لغو تنها اقدامی است که سفارشِ پرداخت‌شده را یک‌طرفه برمی‌گرداند.
 *
 * پس این صف فقط می‌گوید «موافقم»؛ اجرا از همان `POST /admin/orders/{order}/force-cancel`
 * انجام می‌شود، با تیکت و دلیلِ خودش.
 */
class AdminSupplierRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(SupplierRequest::TYPES)],
            'status' => ['nullable', Rule::in([
                SupplierRequest::STATUS_PENDING,
                SupplierRequest::STATUS_APPROVED,
                SupplierRequest::STATUS_REJECTED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = SupplierRequest::query()
            ->with(['supplier.profile', 'order'])
            ->when($request->filled('type'), fn (Builder $q) => $q->where(SupplierRequest::TYPE, $request->input('type')))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(SupplierRequest::STATUS, $request->input('status')),
                // پیش‌فرض صفِ کار است نه کلِ تاریخچه.
                fn (Builder $q) => $q->where(SupplierRequest::STATUS, SupplierRequest::STATUS_PENDING)
            )
            ->latest(SupplierRequest::ID)
            ->paginate($this->perPage(20));

        return success($requests->through(fn (SupplierRequest $r) => $this->present($r)));
    }

    public function approve(Request $request, SupplierRequest $supplierRequest): JsonResponse
    {
        return $this->review($request, $supplierRequest, SupplierRequest::STATUS_APPROVED);
    }

    public function reject(Request $request, SupplierRequest $supplierRequest): JsonResponse
    {
        return $this->review($request, $supplierRequest, SupplierRequest::STATUS_REJECTED);
    }

    private function review(Request $request, SupplierRequest $supplierRequest, string $status): JsonResponse
    {
        $data = $request->validate([
            // ردِ بی‌دلیل همان بن‌بستی است که در ردِ درخواستِ تأمین‌کنندگی
            // داشتیم: تأمین‌کننده «رد شد» می‌بیند و نمی‌داند چه کند.
            'note' => [$status === SupplierRequest::STATUS_REJECTED ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [
            'note.required' => __('admin.supplier_requests.note_required'),
        ]);

        // بررسیِ دوباره‌ی یک درخواستِ بسته‌شده یعنی بازنویسیِ تصمیمِ قبلی و
        // نامِ بررسی‌کننده — سابقه است، نه فیلدِ قابلِ ویرایش.
        $applied = DB::transaction(function () use ($supplierRequest, $status, $data) {
            /** @var SupplierRequest $locked */
            $locked = SupplierRequest::whereKey($supplierRequest->getKey())->lockForUpdate()->first();

            if (! $locked->isPending()) {
                return false;
            }

            // ⚠️ **تنها نوعی که پذیرشش اقدام می‌کند.**
            //
            // قاعده‌ی بالای این کلاس («فقط تصمیم ثبت می‌شود») برای لغو و کاتالوگ
            // نوشته شده و دلیلش این است که برای هر دو یک مسیرِ اجراییِ دیگر با
            // شرط‌های خودش وجود دارد — `forceCancel` با تیکت و دلیل، و ساختِ
            // دستیِ کالا در کاتالوگ. برای قیمت چنین مسیری **نیست**: اگر پذیرش
            // اعمال نکند، ادمین باید همان عدد را دستی در کاتالوگ دوباره تایپ
            // کند، یعنی یک «تأییدِ بی‌اثر» که خطای انسانی هم اضافه می‌کند.
            //
            // داخلِ همین تراکنش و پس از قفلِ ردیف است، پس دو ادمینِ هم‌زمان
            // نمی‌توانند دو بار اعمالش کنند.
            if ($status === SupplierRequest::STATUS_APPROVED
                && $locked->{SupplierRequest::TYPE} === SupplierRequest::TYPE_PRICE_CHANGE) {
                $this->applyPriceChange($locked);
            }

            $locked->forceFill([
                SupplierRequest::STATUS => $status,
                SupplierRequest::REVIEW_NOTE => $data['note'] ?? null,
                SupplierRequest::REVIEWED_BY => Auth::id(),
                SupplierRequest::REVIEWED_AT => now(),
            ])->save();

            return true;
        });

        if (! $applied) {
            return error(__('admin.supplier_requests.already_reviewed'), [], 409);
        }

        activity()
            ->performedOn($supplierRequest)
            ->causedBy(Auth::user())
            ->withProperties(['status' => $status, 'note' => $data['note'] ?? null])
            ->log('admin.supplier_request.reviewed');

        return success(
            $this->present($supplierRequest->refresh()),
            __('admin.supplier_requests.reviewed')
        );
    }

    /**
     * اعمالِ قیمتِ پیشنهادی روی ردیفِ `product_prices`.
     *
     * ردیفِ قیمت ممکن است بینِ ثبتِ درخواست و تأیید حذف شده باشد (تأمین‌کننده
     * کارخانه‌اش را برداشته). آن حالت **سکوت نمی‌کند**: درخواست بدونِ اثر تأیید
     * می‌شد و هیچ‌کس نمی‌فهمید چرا قیمت عوض نشد.
     *
     * ⚠️ `update()` روی **مدل** است نه کوئری‌بیلدر — عمدی. `ProductPrice::booted()`
     * کشِ قیمتِ واریانت را روی رویدادِ `updated` فلاش می‌کند، و
     * `Model::query()->update()` رویدادِ مدل نمی‌زند؛ آن‌وقت تأیید موفق می‌شد،
     * دیتابیس درست بود و **سایت تا انقضای TTL قیمتِ قدیمی را نشان می‌داد**.
     */
    private function applyPriceChange(SupplierRequest $request): void
    {
        $payload = $request->{SupplierRequest::PAYLOAD} ?? [];
        $priceId = $payload['product_price_id'] ?? null;

        $price = $priceId !== null
            ? ProductPrice::whereKey($priceId)->lockForUpdate()->first()
            : null;

        if ($price === null) {
            throw new BusinessException(__('admin.supplier_requests.price_row_missing'));
        }

        $values = [];

        foreach ([ProductPrice::GENERAL_PRICE, ProductPrice::SPECIAL_PRICE, ProductPrice::GOLDEN_FIGURE] as $field) {
            if (! array_key_exists('proposed_'.$field, $payload)) {
                continue;
            }

            /*
             * ⚠️ **پایه از زیرِ پای درخواست تکان خورده باشد، اعمال نمی‌کنیم.**
             *
             * بینِ ثبتِ درخواست و تأییدِ ادمین، قیمتِ پایه می‌تواند عوض شده باشد —
             * با یک تغییرِ زیرِ آستانه‌ی خودِ تأمین‌کننده، یا دستِ ادمینِ دیگر. اگر
             * کورکورانه بنشانیم، دو چیز خراب می‌شود: قیمتِ **تازه‌تر** بی‌صدا
             * بازنویسی می‌شود، و درصدی که ادمین دید و بر مبنایش تصمیم گرفت دیگر
             * درست نیست (ممکن است حالا اصلاً زیرِ آستانه باشد).
             *
             * پس ۴۰۹ می‌دهیم تا ادمین با عددِ واقعیِ امروز دوباره تصمیم بگیرد —
             * همان انضباطِ بازبینیِ زیرِ قفل که `CancelOrderAction` دارد.
             * مقایسه با اپسیلن است چون ستون decimal است و رشته برمی‌گردد.
             */
            $atRequest = $payload['current_'.$field] ?? null;

            if ($atRequest !== null && abs((float) $price->{$field} - (float) $atRequest) >= 0.005) {
                throw new BusinessException(__('admin.supplier_requests.price_moved_since_request'), 409);
            }

            $values[$field] = $payload['proposed_'.$field];
        }

        if ($values === []) {
            throw new BusinessException(__('admin.supplier_requests.price_payload_empty'));
        }

        $values[ProductPrice::LAST_UPDATED_AT] = now();
        $price->update($values);

        /*
         * تأیید، مبنای آستانه را هم جابه‌جا می‌کند — همین‌جا معنیِ «آخرین قیمتِ
         * تأییدشده» ساخته می‌شود.
         *
         * ⚠️ بدونِ این خط، مبنا روی عددِ قدیمی می‌ماند و تغییرِ **بعدی** نسبت به
         * چیزی سنجیده می‌شود که دیگر واقعیت ندارد: قیمتی که ادمین همین حالا
         * پذیرفت، دفعه‌ی بعد باز هم «۴۰٪ انحراف» به حساب می‌آمد و هر تغییرِ
         * کوچکی پس از آن بی‌دلیل به صف می‌رفت.
         */
        $price->refresh();
        $price->fillApprovedBaseline();
        $price->save();
    }

    /** @return array<string, mixed> */
    private function present(SupplierRequest $r): array
    {
        return [
            'id' => $r->getKey(),
            SupplierRequest::TYPE => $r->{SupplierRequest::TYPE},
            SupplierRequest::STATUS => $r->{SupplierRequest::STATUS},
            'supplier' => [
                'user_id' => $r->{SupplierRequest::SUPPLIER_USER_ID},
                'name' => $r->supplier?->profile?->name,
            ],
            SupplierRequest::ORDER_ID => $r->{SupplierRequest::ORDER_ID},
            'order_number' => $r->order?->{Order::ORDER_NUMBER},
            SupplierRequest::REASON => $r->{SupplierRequest::REASON},
            SupplierRequest::PAYLOAD => $r->{SupplierRequest::PAYLOAD},
            SupplierRequest::REVIEW_NOTE => $r->{SupplierRequest::REVIEW_NOTE},
            SupplierRequest::REVIEWED_AT => $r->{SupplierRequest::REVIEWED_AT},
            // فقط `price_change` مهلت دارد؛ برای بقیه null است و پنل ستون را خالی
            // می‌گذارد — «مهلت ندارد» و «مهلتش گذشته» دو چیز متفاوت‌اند.
            SupplierRequest::REVIEW_DEADLINE => $r->{SupplierRequest::REVIEW_DEADLINE},
            'created_at' => $r->created_at,
        ];
    }
}
