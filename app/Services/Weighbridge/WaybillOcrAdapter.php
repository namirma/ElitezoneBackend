<?php

declare(strict_types=1);

namespace App\Services\Weighbridge;

use App\Exceptions\BusinessException;
use App\Models\Shipment;

/**
 * خواندن وزن از تصویر بارنامه — **اسکلت** (بند ۳.۷، راهکار ۲).
 *
 * ⚠️ مثل `WeighbridgeApiAdapter` عمداً غیرفعال است. سرویس OCR هنوز انتخاب
 * نشده، و برگرداندنِ حدسی به‌جای throw یعنی یک وزنِ نادرست مستقیم وارد تعدیل
 * مالی شود.
 *
 * وقتی وصل شد: خروجی OCR **همیشه** باید از تأیید بک‌آفیس بگذرد، دقیقاً مثل
 * `ManualEntryAdapter` — تشخیصِ رقم روی تصویرِ کاغذیِ گردوخاکی قابل اعتمادِ
 * بی‌قید نیست و اشتباهش قابلِ بازگشت نیست.
 */
class WaybillOcrAdapter implements WeightSourceInterface
{
    public function code(): string
    {
        return 'ocr';
    }

    public function isAvailable(): bool
    {
        return (bool) config('weighbridge.ocr.enabled', false);
    }

    public function readWeight(Shipment $shipment, array $payload = []): float
    {
        throw new BusinessException(__('shipments.weight_source_unavailable', ['source' => $this->code()]), 503);
    }
}
