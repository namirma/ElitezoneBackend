<?php

declare(strict_types=1);

namespace App\Services\Weighbridge;

use App\Exceptions\BusinessException;
use App\Models\Shipment;

/**
 * منبعِ وزنِ یک محموله — بند ۳.۷ سند.
 *
 * قاعده‌ی حاکم: **«وزن تایپ نمی‌شود.»** عددی که مستقیم از فرم می‌آید هیچ
 * پشتوانه‌ای ندارد، در حالی که کلِ تعدیل مالی رویش سوار است. سه راه پیش‌بینی
 * شده و هر سه از همین رابط می‌گذرند تا `RegisterShipmentAction` نداند وزن از
 * کجا آمده:
 *
 *   ۱. `WeighbridgeApiAdapter` — اتصال مستقیم به سامانه‌ی باسکول (اسکلت)
 *   ۲. `WaybillOcrAdapter`     — خواندن از تصویرِ بارنامه (اسکلت)
 *   ۳. `ManualEntryAdapter`    — ثبت دستی با تأیید بک‌آفیس ← **تنها adapter
 *      فعال در نسخه‌ی اول**
 *
 * دو تای اول عمداً اسکلت‌اند و `isAvailable() === false` برمی‌گردانند: سند
 * می‌خواهد جای اتصالشان از حالا مشخص باشد، ولی سرویسِ بیرونی‌اش هنوز نیست.
 * پیاده‌سازیِ الکیِ «انگار وصل است» بدتر از نبودن بود.
 */
interface WeightSourceInterface
{
    /** شناسه‌ی ماندگارِ این منبع — همان چیزی که در `shipments.weight_source` می‌نشیند. */
    public function code(): string;

    /**
     * آیا این منبع همین حالا قابل استفاده است؟
     *
     * `RegisterShipmentAction` پیش از خواندن وزن این را می‌پرسد تا خطای
     * «سرویس در دسترس نیست» پیش از هر نوشتنی برگردد، نه وسطِ تراکنش.
     */
    public function isAvailable(): bool;

    /**
     * وزنِ واقعی محموله بر حسب همان واحدی که `shipments.weight` دارد.
     *
     * @param  array<string, mixed>  $payload  ورودیِ خاصِ همان منبع — برای
     *                                         `manual` عددِ اپراتور، برای `ocr`
     *                                         مسیرِ تصویر، برای `api` چیزی لازم ندارد.
     *
     * @throws BusinessException وقتی منبع نتواند وزنِ معتبر بدهد.
     */
    public function readWeight(Shipment $shipment, array $payload = []): float;
}
