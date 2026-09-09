<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupplierRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * انقضای درخواستِ تغییرِ قیمتی که ادمین در مهلت به آن نرسیده.
 *
 * ⚠️ **منقضی‌شدن = رد شدن، نه پذیرفته‌شدن.** مدیریت مهلت را تعیین کرد ولی نگفت
 * پس از آن چه شود؛ این انتخابِ ماست و سمتِ محافظه‌کارانه است:
 *
 *   قیمتی که هیچ ادمینی ندید نباید با **سکوت** روی سایت بنشیند. و ردشدن
 *   برگشت‌پذیر است — تأمین‌کننده همان تغییر را دوباره ثبت می‌کند و مهلتِ تازه
 *   می‌گیرد. پذیرشِ خودکار برگشت‌پذیر نیست: قیمت رفته و مشتری دیده است.
 *
 * ساعتی اجرا می‌شود نه دقیقه‌ای، چون مهلت با «ساعت» سنجیده می‌شود — همان
 * انتخابی که `orders:expire-overweight` دارد.
 *
 * `reviewed_by` عمداً **null** می‌ماند: هیچ ادمینی این تصمیم را نگرفت. پرکردنش
 * با شناسه‌ی یک ادمین، سابقه را دروغ می‌کرد.
 */
class ExpirePriceChangeRequests extends Command
{
    protected $signature = 'supplier-requests:expire-price-changes';

    protected $description = 'Reject price-change requests whose admin review deadline has passed';

    public function handle(): int
    {
        $expired = 0;

        SupplierRequest::query()
            ->where(SupplierRequest::TYPE, SupplierRequest::TYPE_PRICE_CHANGE)
            ->where(SupplierRequest::STATUS, SupplierRequest::STATUS_PENDING)
            ->whereNotNull(SupplierRequest::REVIEW_DEADLINE)
            ->where(SupplierRequest::REVIEW_DEADLINE, '<=', now())
            ->chunkById(100, function ($requests) use (&$expired) {
                foreach ($requests as $request) {
                    if ($this->expire($request)) {
                        $expired++;
                    }
                }
            });

        $this->info("Expired price-change requests: {$expired}");

        return self::SUCCESS;
    }

    /**
     * ⚠️ زیرِ قفل دوباره بررسی می‌شود.
     *
     * بینِ SELECT کرون و رسیدن به این ردیف، ادمین می‌تواند همان درخواست را تأیید
     * کرده باشد. بدونِ این بازبینی، کرون یک درخواستِ **تأییدشده** را «منقضی»
     * علامت می‌زد و تصمیمِ ادمین را بی‌صدا بازنویسی می‌کرد — همان اشتباهی که
     * `CancelOrderAction` برای سفارشِ پرداخت‌شده داشت.
     */
    private function expire(SupplierRequest $request): bool
    {
        return (bool) DB::transaction(function () use ($request) {
            /** @var SupplierRequest $locked */
            $locked = SupplierRequest::whereKey($request->getKey())->lockForUpdate()->first();

            if (! $locked->isPending()) {
                return false;
            }

            $locked->forceFill([
                SupplierRequest::STATUS => SupplierRequest::STATUS_REJECTED,
                SupplierRequest::REVIEW_NOTE => __('admin.supplier_requests.price_review_expired'),
                SupplierRequest::REVIEWED_BY => null,
                SupplierRequest::REVIEWED_AT => now(),
            ])->save();

            return true;
        });
    }
}
