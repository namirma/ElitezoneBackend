<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\BaseJsonResource;
use App\Support\SupportIdentity;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\TicketReply
 */
class TicketReplyResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // مرجعِ «این پاسخ از طرف پشتیبانی است» فقط ستون admin_id است — نه بارگذاری
        // شدنِ relation. (relation ممکن است lazy نباشد یا کاربر حذف شده باشد.)
        $isAdmin = $this->admin_id !== null;

        // هویتِ پشتیبان (نام + حوزه + تصویرِ تأییدشده) از یک منبعِ واحد می‌آید تا
        // دو کلاینت دو شکلِ متفاوت نشان ندهند. تصویرِ در انتظارِ تأیید هرگز از
        // آنجا بیرون نمی‌آید.
        $support = $isAdmin ? SupportIdentity::forAdmin($this->admin) : null;

        return [
            'id' => $this->id,
            'message' => $this->message,
            // قرارداد فرانت‌ها: هم کلیدِ بولینِ صریح، هم بلوکِ admin (کدهای قدیمی
            // با `Boolean(reply.admin)` تشخیص می‌دادند) — هر دو نگه داشته می‌شوند.
            'is_admin' => $isAdmin,
            'author_name' => $isAdmin ? $support['display_name'] : $this->displayUserName(),
            'user' => $this->user,
            'admin' => $isAdmin ? [
                'id' => $this->admin_id,
                // `name` عمداً همان رشته‌ی کاملِ «نام (حوزه)» است: مصرف‌کننده‌های
                // قدیمی این کلید را مستقیم چاپ می‌کردند و نباید ناگهان فقط نام
                // خالی ببینند.
                'name' => $support['display_name'],
                'full_name' => $support['name'],
                'area' => $support['area'],
                'avatar_url' => $support['avatar_url'],
            ] : null,
            'attachments' => TicketFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    private function displayUserName(): ?string
    {
        $user = $this->whenLoaded('user');
        if (! $user instanceof \App\Models\User) {
            return null;
        }

        $profile = $user->relationLoaded('profile') ? $user->profile : null;
        $full = trim(($profile->name ?? '').' '.($profile->family ?? ''));

        return $full !== '' ? $full : $user->mobile;
    }
}
