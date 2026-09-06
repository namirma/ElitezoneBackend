<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\PageBlock;
use App\Services\Content\PageBlockSchema;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\PageBlock
 */
class PageBlockResource extends BaseJsonResource
{
    /**
     * `page`/`is_active` روی اندپوینتِ عمومی زائدند (صفحه از قبل فیلتر شده و
     * ردیفِ غیرفعال اصلاً برنمی‌گردد) اما مخفی‌کردنشان فقط آن‌جا یعنی شرطِ
     * اضافه بدونِ هیچ فایده‌ی امنیتی — هیچ‌کدام محرمانه نیستند. پس همیشه
     * برگردانده می‌شوند؛ همین ریسورس هم پنلِ ادمین را (که به هردو نیاز دارد
     * تا صفحه‌ی بلوک و وضعیتِ فعال/غیرفعال را نشان دهد) و هم سایت را سرویس می‌دهد.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->{PageBlock::ID},
            'page' => $this->{PageBlock::PAGE},
            'type' => $this->{PageBlock::TYPE},
            'sort_order' => $this->{PageBlock::SORT_ORDER},
            'is_active' => $this->{PageBlock::IS_ACTIVE},
            'data' => $this->resolveMedia($this->{PageBlock::DATA} ?? []),
        ];
    }

    /**
     * مسیرهای ذخیره‌شده به URL کامل تبدیل می‌شوند، در هر عمقی.
     *
     * کلیدهای مدیا با نامشان شناخته می‌شوند — نه با حدس زدنِ محتوای رشته —
     * ولی فهرستِ این نام‌ها دیگر این‌جا دستی نگه داشته نمی‌شود:
     * `PageBlockSchema::imageKeys()` هر کلیدی که در اسکیما `type: image`
     * دارد را برمی‌گرداند. یک فهرستِ دستیِ جدا از اسکیما همان چیزی بود که
     * تغییرِ نامِ `poster` به `posterSrc` را بی‌صدا از قلم انداخت. مقداری که
     * از قبل `http` یا `/` است دست نمی‌خورد، تا محتوای سیدشده که به
     * `/media/...`ِ خودِ سایت اشاره می‌کند سالم بماند.
     */
    private function resolveMedia(array $data, ?array $mediaKeys = null): array
    {
        $mediaKeys ??= app(PageBlockSchema::class)->imageKeys();

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->resolveMedia($value, $mediaKeys);

                continue;
            }

            if (! in_array($key, $mediaKeys, true) || ! is_string($value) || $value === '') {
                continue;
            }

            if (str_starts_with($value, 'http') || str_starts_with($value, '/')) {
                continue;
            }

            $data[$key] = Storage::disk('public')->url($value);
        }

        return $data;
    }
}
