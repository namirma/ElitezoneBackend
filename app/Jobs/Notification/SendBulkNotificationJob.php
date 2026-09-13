<?php

namespace App\Jobs\Notification;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ارسال گروهی اعلان درون‌برنامه‌ای از پنل ادمین — و پوشِ همان اعلان.
 *
 * درخواست HTTP فقط dispatch می‌کند؛ درج chunk-شده اینجا انجام می‌شود تا با
 * تعداد زیاد کاربر، درخواست ادمین timeout نخورد.
 *
 * درجِ این جاب `insertOrIgnore` است که رویدادهای مدل را دور می‌زند، پس قلابِ
 * پوشِ `UserNotification::created` برایش اجرا نمی‌شود. پوش را خودش، تکه‌به‌تکه،
 * با `SendBulkPushJob` می‌فرستد — شرحِ «فقط گیرندگانِ تازه» در `sendChunk`.
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
                    $this->sendChunk($users->pluck(User::ID)->map(fn ($id) => (int) $id)->all(), $buildRows);
                });

            return;
        }

        foreach (array_chunk($this->userIds, 500) as $chunk) {
            $this->sendChunk(array_map('intval', $chunk), $buildRows);
        }
    }

    /**
     * یک تکه: ردیف‌های اعلان را درج می‌کند و به **همان‌هایی که تازه درج شدند** پوش می‌دهد.
     *
     * ⚠️ **چرا اول «تازه‌ها» را جدا می‌کنیم.** این جاب `tries = 3` دارد و تکرار را
     * با `batchId` ثابت + ایندکسِ یکتای `(user_id, batch_id)` بی‌اثر می‌کند: اجرای
     * دوم ردیف‌های قبلی را بی‌صدا رد می‌کند. ولی اگر پوش را برای **کلِ** تکه
     * dispatch می‌کردیم، retry همان تکه‌هایی را که قبلاً رفته بودند **دوباره پوش
     * می‌کرد** — ردیف رد می‌شد ولی گوشیِ کاربر دو بار زنگ می‌خورد. در یک ارسالِ
     * همگانی یعنی هزاران نوتیفِ تکراری از یک شکستِ میانه‌راه.
     *
     * `insertOrIgnore` با وجودِ این فیلتر می‌ماند: هزینه‌اش صفر است و اگر روزی دو
     * worker یک batch را هم‌زمان برداشتند، ایندکسِ یکتا به‌جای خطا بی‌صدا ردشان
     * می‌کند.
     *
     * پوش بهترین‌تلاش است و ردیف منبعِ حقیقت: اگر درج موفق شد و dispatch نشد،
     * retryِ بعدی آن تکه را دیگر «تازه» نمی‌بیند و پوشش هرگز نمی‌رود. کاربر اعلان
     * را در کارتابل دارد؛ این بهتر از تکرار است.
     *
     * @param  list<int>  $ids
     * @param  callable(list<int>): array<int, array<string, mixed>>  $buildRows
     */
    private function sendChunk(array $ids, callable $buildRows): void
    {
        $already = UserNotification::query()
            ->where(UserNotification::BATCH_ID, $this->batchId)
            ->whereIn(UserNotification::USER_ID, $ids)
            ->pluck(UserNotification::USER_ID)
            ->map(fn ($id) => (int) $id)
            ->all();

        $fresh = array_values(array_diff($ids, $already));

        if ($fresh === []) {
            return;
        }

        UserNotification::insertOrIgnore($buildRows($fresh));

        SendBulkPushJob::dispatch($fresh, $this->subject, $this->body);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendBulkNotificationJob failed after retries', [
            'subject' => $this->subject,
            'target' => is_null($this->userIds) ? 'all' : count($this->userIds),
            'error' => $e->getMessage(),
        ]);
    }
}
