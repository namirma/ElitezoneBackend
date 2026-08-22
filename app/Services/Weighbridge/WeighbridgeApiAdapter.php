<?php

declare(strict_types=1);

namespace App\Services\Weighbridge;

use App\Exceptions\BusinessException;
use App\Models\Shipment;

/**
 * اتصال به سامانه‌ی باسکول — **اسکلت** (بند ۳.۷، راهکار ۱).
 *
 * ⚠️ عمداً پیاده‌سازی نشده و `isAvailable()` همیشه `false` است. سرویسِ بیرونی
 * هنوز وجود ندارد؛ سند فقط می‌خواهد جای اتصالش از حالا مشخص باشد.
 *
 * چرا کلاسِ خالی به‌جای هیچ: بدون آن، اولین کسی که سامانه‌ی باسکول را وصل
 * می‌کند منطقش را داخل `RegisterShipmentAction` می‌نوشت و همان اتصالِ سختی
 * ساخته می‌شد که این رابط برای اجتنابش وجود دارد. `readWeight` هم به‌جای
 * برگرداندنِ عددِ ساختگی throw می‌کند — یک وزنِ جعلی مستقیم وارد تعدیل مالی
 * می‌شد.
 */
class WeighbridgeApiAdapter implements WeightSourceInterface
{
    public function code(): string
    {
        return 'api';
    }

    public function isAvailable(): bool
    {
        return (bool) config('weighbridge.api.enabled', false);
    }

    public function readWeight(Shipment $shipment, array $payload = []): float
    {
        throw new BusinessException(__('shipments.weight_source_unavailable', ['source' => $this->code()]), 503);
    }
}
