<?php

namespace App\Services\Pricing\Pipes;

use App\Models\PriceFilterCategory;
use App\Models\PriceSetting;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Pricing\PricingContext;
use App\Support\Money;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApplyPriceFilters implements PricingPipe
{
    public function __construct(private readonly SettlementCalculatorService $settlement) {}

    public function handle(PricingContext $context, Closure $next)
    {
        // قیمتِ ثبت‌شده‌ی تأمین‌کننده، پیش از هر فیلترِ نقش‌محور — مبنای PTS (پرسشِ ۶ مدیریت).
        $context->supplierPrice = $context->payablePrice;

        // ۱. دریافت فیلترهای فعال برای این کاربر/محصول
        $filters = $this->getFilters($context);

        if ($filters->isEmpty()) {
            return $next($context);
        }

        $generalFilters = $filters->filter(fn ($f) => optional($f->filterType)->apply_on === 'general');
        $specialFilters = $filters->filter(fn ($f) => optional($f->filterType)->apply_on === 'special');
        $bothSideFilters = $filters->filter(fn ($f) => optional($f->filterType)->apply_on === 'both');

        if ($generalFilters->isNotEmpty()) {
            $context->listPrice = $this->applyFilters($context->listPrice, $generalFilters);
            $context->payablePrice = $this->applyFilters($context->payablePrice, $generalFilters);
        }

        // ۴. اعمال فیلترهای ویژه -> تاثیر روی قیمت قابل پرداخت (Payable Price)
        // مثال: قیمت 37000 - تخفیف مهندس 1000 = 36000
        if ($specialFilters->isNotEmpty()) {
            $context->payablePrice = $this->applyFilters($context->payablePrice, $specialFilters);
        }

        // ۵. اعمال فیلترهای دوطرفه (Adjustments)
        if ($bothSideFilters->isNotEmpty()) {
            // محاسبه مقدار خالص تغییرات (مثلاً +500 -200 = +300)
            $adjustment = $this->applyFilters(0.0, $bothSideFilters);
            $context->payablePrice += $adjustment;
        }

        // ۶. گارد نهایی: قیمت هرگز نباید منفی شود
        $context->payablePrice = max(0.0, $context->payablePrice);
        $context->listPrice = max(0.0, $context->listPrice);

        // ۷. کفِ قراردادی — پاسخِ مدیریت به پرسشِ ۶ (۱۴۰۵/۰۷/۱۳): «قیمت فروش شرکت هرگز
        //    نباید از قیمتِ ثبت‌شده‌ی تأمین‌کننده منهای کارمزدِ قراردادی کمتر باشد تا بتواند
        //    مبلغِ خرید از تأمین‌کننده را تأمین کند؛ بیشتر می‌تواند باشد.» تأمین‌کننده
        //    همیشه P_s × (1 − TC) می‌گیرد، پس خریدار باید پس از تخفیفِ ویژه‌اش (%OW) حداقل
        //    همان را بپردازد: F × (1 − OW) ≥ P_s × (1 − TC)  ⇒  F ≥ P_s(1 − TC)/(1 − OW).
        //    فیلتری که از این پایین‌تر برود استخرِ کمیسیون را منفی می‌کرد — یعنی شرکت از
        //    جیبِ خودش به تأمین‌کننده می‌پرداخت. به کف برمی‌گردد و لاگ می‌شود؛ گاردِ
        //    ادمینِ فیلترِ درصدی هم همین سقف را دارد (AdminPriceFilterController).
        $floor = $this->contractFloor($context);
        if ($floor > 0.0 && $context->payablePrice < $floor) {
            Log::channel('financial')->warning('Price filters pushed the buyer price below the supplier contract floor; clamped.', [
                'variant_id' => $context->variant->id,
                'factory_id' => $context->factory->id,
                'supplier_price' => $context->supplierPrice,
                'filtered_price' => $context->payablePrice,
                'floor' => $floor,
                'category' => $context->filterCategoryCode,
            ]);
            $context->payablePrice = $floor;
            $context->priceFloorApplied = true;
        }

        return $next($context);
    }

    /**
     * کفِ قیمتِ خریدار برای این مبدأ: P_s × (1 − TC_s/100) / (1 − OW_final/100).
     * TC_s کارمزدِ قراردادیِ تأمین‌کننده‌ی همین کارخانه (پرسشِ ۴) و OW_final = TC_s × سهمِ OW.
     */
    private function contractFloor(PricingContext $context): float
    {
        if ($context->supplierPrice <= 0.0) {
            return 0.0;
        }

        $supplierUserId = $context->factory->supplier_user_id;
        $tc = $this->settlement->tcPercentageForSupplier($supplierUserId !== null ? (int) $supplierUserId : null);
        $owShare = PriceSetting::getValueAsFloat(
            PriceSetting::CODE_OW_SHARE,
            (float) PriceSetting::catalog()[PriceSetting::CODE_OW_SHARE]['default']
        );
        $owFinal = (float) Money::mul($tc, Money::div($owShare, '100'));

        $ptsFactor = Money::percentSub($tc);
        $owFactor = Money::percentSub($owFinal);

        return (float) Money::div(Money::mul($context->supplierPrice, $ptsFactor), $owFactor);
    }

    /**
     * منطق ریاضی اعمال فیلترها
     */
    private function applyFilters(float $currentPrice, Collection $filters): float
    {
        // مانند بقیه pipe ها از bcmath استفاده می‌شود — جمع شدن خطای float
        // روی مبالغ میلیاردی (ریال) و فیلترهای زنجیره‌ای قابل قبول نیست.
        $priceStr = (string) $currentPrice;

        // مرتب‌سازی بر اساس اولویت (sort_order)
        foreach ($filters->sortBy('sort_order') as $filter) {
            $amountStr = (string) (float) $filter->amount;
            $isPercent = (bool) $filter->is_percentage;

            if ($isPercent) {
                $rateStr = bcdiv($amountStr, '100', 8);
                $changeStr = bcmul($priceStr, $rateStr, 4);
                $priceStr = bcadd($priceStr, $changeStr, 4);
            } else {
                $priceStr = bcadd($priceStr, $amountStr, 4);
            }
        }

        return (float) $priceStr;
    }

    /**
     * تمام دسته‌بندی‌های فعال + فیلترهایشان را از کش یا دیتابیس برمی‌گرداند.
     * یک کلید واحد → invalidation ساده در model observer.
     */
    private function allCategoriesWithFilters(): Collection
    {
        return Cache::remember('pricing:categories_with_filters', 3600, fn () => PriceFilterCategory::where('is_active', true)
            ->with(['filters.filterType'])
            ->get()
        );
    }

    /**
     * دریافت لیست فیلترها برای context جاری (از کش)
     */
    private function getFilters(PricingContext $context): Collection
    {
        $ids = $context->filterCategoryIds;

        if (empty($ids) && $context->filterCategoryCode) {
            $category = $this->allCategoriesWithFilters()
                ->firstWhere('code', $context->filterCategoryCode);

            if ($category) {
                $ids = [$category->id];
            }
        }

        if (empty($ids)) {
            return collect();
        }

        return $this->allCategoriesWithFilters()
            ->whereIn('id', $ids)
            ->flatMap(fn ($cat) => $cat->filters)
            ->filter(fn ($f) => $f && $f->is_active && $f->filterType)
            ->unique('id')
            ->values();
    }
}
