<?php

declare(strict_types=1);

namespace App\Services\Weighbridge;

use App\Exceptions\BusinessException;
use App\Models\Shipment;

/**
 * ثبت دستی وزن — **تنها adapter فعال در نسخه‌ی اول** (بند ۳.۷).
 *
 * «وزن تایپ نمی‌شود» یعنی عددِ خام از فرم مستقیم به دفتر مالی نمی‌رود، نه
 * اینکه ورودِ دستی ممنوع است. اینجا عدد از اپراتور می‌آید ولی از سه گارد
 * می‌گذرد: مثبت و متناهی باشد، و در بازه‌ی معقولی از وزنِ ثبت‌شده‌ی محموله.
 *
 * گاردِ سوم مهم‌ترین است: خطای تایپیِ یک صفر (۱۲ تن → ۱۲۰ تن) در غیر این صورت
 * مستقیم وارد `WeighbridgeReconciler` می‌شد و یک تعدیلِ ده‌برابری می‌ساخت.
 * سقفِ اضافه‌بارِ سند این را نمی‌گرفت چون آن روی **کلِ سفارش** است و اینجا
 * محموله‌ی منفرد سنجیده می‌شود.
 */
class ManualEntryAdapter implements WeightSourceInterface
{
    /**
     * وزنِ اعلامی می‌تواند تا این ضریب از وزنِ برنامه‌ریزی‌شده‌ی محموله فاصله
     * بگیرد. ۱۰ برابر عمداً سخاوتمندانه است — این گاردِ خطای تایپی است، نه
     * گاردِ کسب‌وکار؛ تلورانسِ واقعی جای دیگری اعمال می‌شود.
     */
    private const SANITY_FACTOR = 10.0;

    public function code(): string
    {
        return 'manual';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function readWeight(Shipment $shipment, array $payload = []): float
    {
        $weight = (float) ($payload['actual_weight'] ?? 0);

        if (! is_finite($weight) || $weight <= 0) {
            throw new BusinessException(__('shipments.weight_must_be_positive'), 422);
        }

        $planned = (float) $shipment->{Shipment::WEIGHT};

        if ($planned > 0 && ($weight > $planned * self::SANITY_FACTOR || $weight < $planned / self::SANITY_FACTOR)) {
            throw new BusinessException(__('shipments.weight_out_of_sane_range'), 422);
        }

        return $weight;
    }
}
