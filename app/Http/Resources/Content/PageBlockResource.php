<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\PageBlock;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\PageBlock
 */
class PageBlockResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->{PageBlock::ID},
            'type' => $this->{PageBlock::TYPE},
            'sort_order' => $this->{PageBlock::SORT_ORDER},
            'data' => $this->resolveMedia($this->{PageBlock::DATA} ?? []),
        ];
    }

    /**
     * مسیرهای ذخیره‌شده به URL کامل تبدیل می‌شوند، در هر عمقی.
     *
     * کلیدهای مدیا با نامشان شناخته می‌شوند (`image`, `poster`) — نه با حدس زدنِ
     * محتوای رشته. مقداری که از قبل `http` یا `/` است دست نمی‌خورد، تا محتوای
     * سیدشده که به `/media/...`ِ خودِ سایت اشاره می‌کند سالم بماند.
     */
    private function resolveMedia(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->resolveMedia($value);

                continue;
            }

            if (! in_array($key, ['image', 'poster'], true) || ! is_string($value) || $value === '') {
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
