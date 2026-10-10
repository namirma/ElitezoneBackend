<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\Shipment;
use Illuminate\Http\Request;

/**
 * @mixin Shipment
 */
class ShipmentResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Shipment::ID => $this->id,

            'origin' => [
                'factory_id' => $this->factory_id,
                'factory_location_id' => $this->factory_location_id,
                'factory_name' => $this->whenLoaded('originFactory', fn () => $this->originFactory?->name),
                'location_name' => $this->whenLoaded('originFactoryLocation', fn () => $this->originFactoryLocation?->name),
            ],

            'destination' => [
                'address_id' => $this->address_id,
                'province' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->province?->name),
                'city' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->city?->name),
                'full_address' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->postal_address),
                'receiver' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->receiver_name),
            ],

            'weight' => (string) $this->weight,
            Shipment::BILLED_COUNT => (string) $this->billed_count,

            // How freight was billed for this leg (e.g. rebar by weight, brick by count).
            'cost_basis' => $this->cost_basis?->value,
            'cost_basis_label' => $this->cost_basis?->label(),

            Shipment::SHIPPING_COST => (string) $this->shipping_cost,
            Shipment::STATUS => $this->status,

            // ── بارنامه و راننده (فاز ۷) ──────────────────────────────────────
            // `null` تا وقتی تأمین‌کننده بارنامه را ثبت نکرده. فرانت از
            // `is_registered` شاخه بزند و نه از خالی‌بودنِ تک‌تکِ فیلدها —
            // کد ملی و موبایل حتی پس از ثبت هم می‌توانند خالی باشند.
            'is_registered' => $this->registered_at !== null,
            Shipment::WAYBILL_REF => $this->waybill_ref,
            Shipment::REGISTERED_AT => $this->registered_at?->toIso8601String(),
            'actual_weight' => $this->actual_weight !== null ? (string) $this->actual_weight : null,

            'driver' => $this->registered_at === null ? null : [
                'name' => $this->driver_name,
                'national_code' => $this->driver_national_code,
                'mobile' => $this->driver_mobile,
            ],

            'vehicle' => $this->registered_at === null ? null : [
                'plate' => $this->vehicle_plate,
                'type' => $this->vehicle_type,
            ],

            // ── چرخه‌ی تحویل (فاز ۱۰) ─────────────────────────────────────────
            // `is_final` را فرانت هم می‌خواند: چک‌باکس «آخرین محموله» پس از ثبت
            // باید حالتش را نشان دهد، نه اینکه دوباره خالی برگردد.
            Shipment::IS_FINAL => (bool) $this->is_final,

            'delivery' => [
                'arrived_at' => $this->arrived_at?->toIso8601String(),
                'arrival_source' => $this->arrival_source,
                'deadline' => $this->delivery_deadline?->toIso8601String(),
                // نسبی و نه مطلق، به همان دلیلِ سبد: ساعتِ کلاینت ممکن است
                // اختلاف داشته باشد و شمارش معکوس روی عدد مطلق غلط درمی‌آید.
                // `(int)`: Carbon 3 اعشاری برمی‌گرداند و JSON عددِ کسری می‌شد.
                'remaining_seconds' => $this->delivery_deadline && $this->delivered_at === null && $this->returned_at === null
                    ? (int) max(0, now()->diffInSeconds($this->delivery_deadline, false))
                    : null,
                'delivered_at' => $this->delivered_at?->toIso8601String(),
                'returned_at' => $this->returned_at?->toIso8601String(),
                'can_confirm' => $this->arrived_at !== null
                    && $this->delivered_at === null
                    && $this->returned_at === null,
            ],
        ];
    }
}
