<?php

namespace App\Services\Pricing\Pipes;

use App\Models\PriceFilterCategory;
use App\Models\PriceSetting;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Pricing\PricingContext;
use App\Services\PricingRoleResolver;
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

        $context->buyerDiscountPercent = $this->buyerDiscountPercent($context);

        // ۱. دریافت فیلترهای فعال برای این کاربر/محصول — بدونِ فیلترهای تخفیف (مدیریت، دورِ پنجم).
        $filters = $this->withoutDiscounts($this->getFilters($context));

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

        // ۷. کف — پاسخِ مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴): «هیچ‌گاه قیمتِ فروش نباید کمتر از قیمتِ
        //    اعلام‌شده توسطِ تأمین‌کننده منهای تخفیفِ محاسبه‌شده برای خریدارِ دارای الیت‌یار باشد» —
        //    تخفیفِ خریدار سطرِ جداست (ثبتِ سفارش)، پس پیش از آن کف = خودِ قیمتِ تأمین‌کننده.
        //    فیلترهای منفی بالاتر کنار گذاشته شده‌اند، پس این فقط دفاعِ دوم است.
        if ($context->supplierPrice > 0.0 && $context->payablePrice < $context->supplierPrice) {
            Log::channel('financial')->warning('Price filters pushed the buyer price below the supplier price; clamped.', [
                'variant_id' => $context->variant->id,
                'factory_id' => $context->factory->id,
                'supplier_price' => $context->supplierPrice,
                'filtered_price' => $context->payablePrice,
                'category' => $context->filterCategoryCode,
            ]);
            $context->payablePrice = $context->supplierPrice;
            $context->priceFloorApplied = true;
        }

        return $next($context);
    }

    /**
     * «هیچ تخفیفِ دیگری در سیستم وجود ندارد» (مدیریت، دورِ پنجم، پرسش ۴): فیلترِ قیمتِ منفی —
     * همان «تخفیفِ نقش‌محور» — اعمال نمی‌شود؛ تخفیفِ بیشتر فقط با کوپن از کیف پولِ کوپن‌ها.
     * افزایشِ قیمت (فیلترِ مثبت) ممنوع نشده و می‌ماند.
     */
    private function withoutDiscounts(Collection $filters): Collection
    {
        return $filters->filter(fn ($f) => (float) $f->amount >= 0)->values();
    }

    /**
     * درصدِ تخفیفِ ویژه‌ی خریدار برای این مبدأ (سهمِ خریدار از کارمزدِ قراردادیِ تأمین‌کننده) —
     * فقط برای گروهِ «با الیت‌یار»؛ کارتِ محصول آن را با عنوانِ «تخفیف» نشان می‌دهد (مدیریت،
     * دورِ پنجم، پرسشِ ۱). مبلغِ نهایی همچنان در ثبتِ سفارش حساب می‌شود.
     */
    private function buyerDiscountPercent(PricingContext $context): float
    {
        if ($context->filterCategoryCode !== PricingRoleResolver::WITH_ELITE_YAR) {
            return 0.0;
        }

        $supplierUserId = $context->factory->supplier_user_id;
        $tc = $this->settlement->tcPercentageForSupplier($supplierUserId !== null ? (int) $supplierUserId : null);
        $owShare = PriceSetting::getValueAsFloat(
            PriceSetting::CODE_OW_SHARE,
            (float) PriceSetting::catalog()[PriceSetting::CODE_OW_SHARE]['default']
        );

        return (float) Money::mul($tc, Money::div($owShare, '100'));
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
