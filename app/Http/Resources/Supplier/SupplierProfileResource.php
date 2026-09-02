<?php

namespace App\Http\Resources\Supplier;

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
