<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Driver;
use App\Support\OtpSecret;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * دفترچه‌ی رانندگانِ حمل‌کننده — §۷.۳.
 *
 * ⚠️ **هر کوئری به `carrier_user_id` کاربرِ احرازشده مقید است.** راننده با
 * route-model-binding می‌آید، پس بدون این قید یک حمل‌کننده می‌توانست با حدسِ
 * شناسه، راننده‌ی رقیبش را بخواند یا حذف کند. `assertOwned` تنها جایی است که
 * این قید نوشته می‌شود.
 */
class SupplierDriverController extends Controller
{
    /** مهلت کد تأیید موبایل راننده. کوتاه، چون در همان لحظه‌ی ثبت زده می‌شود. */
    private const OTP_TTL_MINUTES = 3;

    /** GET /api/profile/supplier/drivers */
    public function index(Request $request): JsonResponse
    {
        $drivers = Driver::query()
            ->where(Driver::CARRIER_USER_ID, (int) Auth::id())
            ->when($request->boolean('only_active'), fn ($q) => $q->where(Driver::IS_ACTIVE, true))
            ->orderByDesc(Driver::ID)
            ->get()
            ->map(fn (Driver $d) => $this->present($d))
            ->all();

        return success(['drivers' => $drivers]);
    }

    /** POST /api/profile/supplier/drivers */
    public function store(Request $request): JsonResponse
    {
        $carrierId = (int) Auth::id();
        $data = $this->validated($request, $carrierId, null);

        $driver = Driver::create($data + [Driver::CARRIER_USER_ID => $carrierId]);

        return created($this->present($driver), __('drivers.created'));
    }

    /** PUT /api/profile/supplier/drivers/{driver} */
    public function update(Request $request, Driver $driver): JsonResponse
    {
        $this->assertOwned($driver);

        $carrierId = (int) Auth::id();
        $data = $this->validated($request, $carrierId, $driver);

        // تغییر شماره یعنی احرازِ قبلی دیگر معتبر نیست — وگرنه می‌شد یک شماره‌ی
        // احرازشده را ثبت کرد و بعد بی‌سروصدا به شماره‌ی دلخواه عوضش کرد.
        if (isset($data[Driver::MOBILE]) && $data[Driver::MOBILE] !== $driver->{Driver::MOBILE}) {
            $driver->forceFill([Driver::MOBILE_VERIFIED_AT => null])->save();
        }

        $driver->update($data);

        return success($this->present($driver->fresh()), __('drivers.updated'));
    }

    /**
     * DELETE /api/profile/supplier/drivers/{driver}
     *
     * غیرفعال‌سازی و نه حذف: بارنامه‌های قبلی به این ردیف اشاره دارند و حذفش
     * تاریخچه‌ی حمل را می‌شکست.
     */
    public function destroy(Driver $driver): JsonResponse
    {
        $this->assertOwned($driver);

        $driver->update([Driver::IS_ACTIVE => false]);

        return success($this->present($driver->fresh()), __('drivers.deactivated'));
    }

    /**
     * POST /api/profile/supplier/drivers/{driver}/verify
     *
     * بدون بدنه ⇒ ارسال کد. با `code` ⇒ بررسی کد.
     *
     * کد در cache می‌ماند نه روی ردیف: OTP ذاتاً گذراست و ستونِ دائمی برایش
     * یعنی یک هشِ مرده که تا ابد روی جدول می‌ماند. سند هم فقط
     * `mobile_verified_at` را خواسته.
     */
    public function verify(Request $request, Driver $driver): JsonResponse
    {
        $this->assertOwned($driver);

        $key = "driver-otp:{$driver->id}";

        if (! $request->filled('code')) {
            $code = generateVerifyCode();
            Cache::put($key, OtpSecret::hash((string) $code), now()->addMinutes(self::OTP_TTL_MINUTES));

            SendSmsJob::template($driver->{Driver::MOBILE}, 'driver_verify', ['code' => $code]);

            return success(['sent' => true], __('drivers.code_sent'));
        }

        $stored = Cache::get($key);

        if (! is_string($stored) || ! OtpSecret::matches($stored, (string) $request->input('code'))) {
            throw new BusinessException(__('drivers.code_invalid'), 422);
        }

        Cache::forget($key);
        $driver->forceFill([Driver::MOBILE_VERIFIED_AT => now()])->save();

        return success($this->present($driver->fresh()), __('drivers.verified'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $carrierId, ?Driver $existing): array
    {
        $data = $request->validate([
            'full_name' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'mobile' => [
                $existing ? 'sometimes' : 'required', 'string', 'max:20',
                // یکتایی per-حمل‌کننده، هم‌راستا با کلیدِ یکتای جدول. بدون
                // `ignore`، ویرایشِ خودِ راننده به شماره‌ی خودش رد می‌شد.
                Rule::unique('drivers', 'mobile')
                    ->where(fn ($q) => $q->where('carrier_user_id', $carrierId))
                    ->ignore($existing?->getKey()),
            ],
            'national_code' => ['nullable', 'string', 'size:10'],
            'plate_number' => ['nullable', 'string', 'max:30'],
            'vehicle_type' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // نرمال‌سازی پیش از ذخیره — قاعده‌ی سراسری پروژه (بند ۴.۶).
        if (isset($data['mobile'])) {
            $data['mobile'] = PhoneNumber::to09($data['mobile']);
        }

        return $data;
    }

    private function assertOwned(Driver $driver): void
    {
        if ((int) $driver->{Driver::CARRIER_USER_ID} !== (int) Auth::id()) {
            throw new BusinessException(__('global.forbidden'), 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'full_name' => $driver->{Driver::FULL_NAME},
            'mobile' => $driver->{Driver::MOBILE},
            'national_code' => $driver->{Driver::NATIONAL_CODE},
            'plate_number' => $driver->{Driver::PLATE_NUMBER},
            'vehicle_type' => $driver->{Driver::VEHICLE_TYPE},
            'is_active' => (bool) $driver->{Driver::IS_ACTIVE},
            'mobile_verified' => $driver->isMobileVerified(),
        ];
    }
}
