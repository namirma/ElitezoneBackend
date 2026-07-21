<?php

namespace App\Jobs\Notification;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * ارسال گروهی اعلان درون‌برنامه‌ای از پنل ادمین.
 *
 * درخواست HTTP فقط dispatch می‌کند؛ درج chunk-شده اینجا انجام می‌شود تا با
 * تعداد زیاد کاربر، درخواست ادمین timeout نخورد.
 */
class SendBulkNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var list<int> ثانیه تا تلاش بعدی */
    public array $backoff = [60, 300];

    /**
     * batch_id ثابت به‌ازای هر dispatch. چون property است و در payloadِ صف serialize
     * می‌شود، در retry همان مقدار می‌ماند → insertOrIgnore ردیف‌های قبلاً درج‌شده را رد
     * می‌کند و از اعلانِ تکراری جلوگیری می‌شود، حتی اگر جاب میانه‌راه شکست بخورد.
     */
    public readonly string $batchId;

    /**
     * @param  list<int>|null  $userIds  null یعنی ارسال همگانی (همه کاربران فعال)
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?array $userIds,
        public readonly ?int $senderId,
    ) {
        $this->batchId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $now = now();

        $buildRows = fn (array $ids): array => array_map(fn (int $id) => [
            UserNotification::USER_ID => $id,
            UserNotification::SENDER_ID => $this->senderId,
            UserNotification::BATCH_ID => $this->batchId,
            UserNotification::SUBJECT => $this->subject,
            UserNotification::BODY => $this->body,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        if (is_null($this->userIds)) {
            User::query()
                ->where(fn ($q) => $q->where(User::IS_ACTIVE, true)->orWhereNull(User::IS_ACTIVE))
                ->select(User::ID)
                ->chunkById(500, function ($users) use ($buildRows) {
                    // insertOrIgnore + unique(user_id, batch_id): اجرای مجددِ جاب ردیف‌های
                    // تکراری را بی‌صدا رد می‌کند و فقط باقی‌مانده را درج می‌کند.
                    UserNotification::insertOrIgnore($buildRows($users->pluck(User::ID)->map(fn ($id) => (int) $id)->all()));
                });

            return;
        }

        foreach (array_chunk($this->userIds, 500) as $chunk) {
            UserNotification::insertOrIgnore($buildRows(array_map('intval', $chunk)));
        }
    }

    public function failed(\Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::error('SendBulkNotificationJob failed after retries', [
            'subject' => $this->subject,
            'target' => is_null($this->userIds) ? 'all' : count($this->userIds),
            'error' => $e->getMessage(),
        ]);
    }
}
