<?php

declare(strict_types=1);

namespace App\Services\Weighbridge;

use App\Exceptions\BusinessException;

/**
 * انتخابِ adapterِ وزن از روی کدش — بند ۳.۷.
 *
 * تنها جایی که کدِ منبع («manual» / «ocr» / «api») به کلاس نگاشت می‌شود. بدون
 * آن، هر فراخوان `match` خودش را می‌نوشت و اضافه‌کردنِ منبعِ چهارم یعنی گشتن
 * دنبال همه‌ی آن‌ها.
 */
class WeightSourceResolver
{
    public function __construct(
        private readonly ManualEntryAdapter $manual,
        private readonly WaybillOcrAdapter $ocr,
        private readonly WeighbridgeApiAdapter $api,
    ) {}

    public function for(?string $code): WeightSourceInterface
    {
        // پیش‌فرضِ `manual` عمدی است: تنها منبعِ فعالِ نسخه‌ی اول همان است، و
        // بارنامه‌های موجود که `weight_source` ندارند باید همان رفتار قبلی را
        // بگیرند نه خطا.
        $source = match ($code ?? 'manual') {
            'manual' => $this->manual,
            'ocr' => $this->ocr,
            'api' => $this->api,
            default => throw new BusinessException(
                __('shipments.weight_source_unknown', ['source' => (string) $code]), 422
            ),
        };

        if (! $source->isAvailable()) {
            throw new BusinessException(
                __('shipments.weight_source_unavailable', ['source' => $source->code()]), 503
            );
        }

        return $source;
    }

    /**
     * منابعی که همین حالا قابل استفاده‌اند — برای نمایش در پنل.
     *
     * @return list<string>
     */
    public function availableCodes(): array
    {
        $available = [];

        foreach ([$this->manual, $this->ocr, $this->api] as $source) {
            if ($source->isAvailable()) {
                $available[] = $source->code();
            }
        }

        return $available;
    }
}
