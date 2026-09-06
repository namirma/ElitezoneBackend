<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\BaseJsonResource;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @mixin TicketReply
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

        return [
            'id' => $this->id,
            'message' => $this->message,
            // قرارداد فرانت‌ها: هم کلیدِ بولینِ صریح، هم بلوکِ admin (کدهای قدیمی
            // با `Boolean(reply.admin)` تشخیص می‌دادند) — هر دو نگه داشته می‌شوند.
            'is_admin' => $isAdmin,
            'author_name' => $isAdmin ? __('ticket.support_author') : $this->displayUserName(),
            'user' => $this->user,
            'admin' => $isAdmin ? [
                'id' => $this->admin_id,
                'name' => __('ticket.support_author'),
            ] : null,
            'attachments' => TicketFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    private function displayUserName(): ?string
    {
        $user = $this->whenLoaded('user');
        if (! $user instanceof User) {
            return null;
        }

        $profile = $user->relationLoaded('profile') ? $user->profile : null;
        $full = trim(($profile->name ?? '').' '.($profile->family ?? ''));

        return $full !== '' ? $full : $user->mobile;
    }
}
