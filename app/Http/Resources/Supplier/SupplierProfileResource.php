<?php

namespace App\Http\Resources\Supplier;

use App\Http\Controllers\Api\User\SupplierDocumentController;
use App\Http\Resources\BaseJsonResource;
use App\Models\SupplierProfile;
use Illuminate\Http\Request;

/**
 * @property mixed $activities
 */
/**
 * @mixin SupplierProfile
 */
class SupplierProfileResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            SupplierProfile::ACTIVITY_DESCRIPTION => $this->activity_description,
            SupplierProfile::BRANDS => $this->brands,
            SupplierProfile::BRANDS_NOT_RELATED => $this->brands_not_related,
            SupplierProfile::ACTIVITY_HISTORY => $this->activity_history,
            SupplierProfile::ACTIVITY_PROVINCES => $this->activity_provinces,
            SupplierProfile::PRODUCTION_AMOUNT => $this->production_amount,
            SupplierProfile::STANDARDS => $this->standards,
            SupplierProfile::STANDARDS_NOT_RELATED => $this->standards_not_related,
            SupplierProfile::WEBSITE => $this->website,
            SupplierProfile::WEBSITE_NOT_RELATED => $this->website_not_related,
            SupplierProfile::COMPANY => $this->company,
            SupplierProfile::STATUS => $this->status,
            // متقاضیِ ردشده باید بداند چه چیزی را اصلاح کند — بدونِ این،
            // مسیرِ «ویرایش = درخواستِ دوباره» مقصدی ندارد.
            SupplierProfile::REJECTION_REASON => $this->rejection_reason,

            // درخواستِ احرازِ صلاحیت (اسپکِ یکپارچه‌سازی §۴-۱) — همان چیزی که سایت
            // در فرم پر می‌کند و پنل بعد از تأیید در «مشخصات شرکت» ویرایش می‌کند.
            SupplierProfile::ENTITY_TYPE => $this->entity_type,
            SupplierProfile::NATIONAL_ID => $this->national_id,
            SupplierProfile::REGISTRATION_NUMBER => $this->registration_number,
            SupplierProfile::ECONOMIC_CODE => $this->economic_code,
            SupplierProfile::REPRESENTATIVE_NAME => $this->representative_name,
            SupplierProfile::REPRESENTATIVE_NATIONAL_CODE => $this->representative_national_code,
            SupplierProfile::LANDLINE => $this->landline,
            SupplierProfile::PROVINCE_ID => $this->province_id,
            SupplierProfile::CITY_ID => $this->city_id,
            SupplierProfile::FACTORY_ADDRESS => $this->factory_address,
            SupplierProfile::POSTAL_CODE => $this->postal_code,
            SupplierProfile::IBAN => $this->iban,
            SupplierProfile::SUBMITTED_AT => $this->submitted_at,
            SupplierProfile::REVIEWED_AT => $this->reviewed_at,
            'documents' => $this->whenLoaded('documents', fn () => $this->resource->documents->map(fn ($d) => SupplierDocumentController::present($d))->all()),

            // ⚠️ این چهار فیلد **در هیچ پاسخی نمی‌آمدند** — نه اینجا، نه در
            // داشبورد، نه در هیچ ریسورسِ دیگری (کلِ مخزن گرفته شد).
            //
            // `reserve_time` مهم‌ترین تنظیمِ تأمین‌کننده است: پنجره‌ی اعتبارِ
            // قیمت‌هایش. وقتی بگذرد، `hasValidPriceWindow()` false می‌شود و
            // `FilterProductsAction::hideProductsWithExpiredPrices` **کلِ کالای
            // او را از کاتالوگ حذف می‌کند**. تأمین‌کننده آن را می‌نوشت، پاسخ
            // همان مقدار را برنمی‌گرداند، و هیچ‌جا نمی‌توانست ببیند منقضی شده —
            // فقط می‌دید که دیگر سفارشی نمی‌آید.
            //
            // `price_window_valid` مشتق است نه ستون؛ همان تابعی که کاتالوگ
            // تصمیمش را با آن می‌گیرد، تا پنل و کاتالوگ نتوانند دو جواب بدهند.
            SupplierProfile::RESERVE_TIME => $this->reserve_time,
            SupplierProfile::RESERVE_AUTO_EXTEND_MINUTES => $this->reserve_auto_extend_minutes,
            SupplierProfile::RESERVE_GRACE_HOURS => $this->reserve_grace_hours,
            'price_window_valid' => $this->hasValidPriceWindow(),

            // پرچم‌های فرمولِ تسویه — فقط‌خواندنی برای تأمین‌کننده (ادمین
            // می‌نویسدشان)، ولی تعیین می‌کنند کمیسیون و مالیات روی کرایه اعمال
            // شود یا نه. تا امروز فقط از داشبورد قابل دیدن بودند.
            SupplierProfile::TRANS_COMMISSION => (bool) $this->trans_commission,
            SupplierProfile::TRANS_VAT => (bool) $this->trans_vat,

            SupplierProfile::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,

            'activity_categories' => SupplierCategoryResource::collection($this->activities),
        ];
    }
}
