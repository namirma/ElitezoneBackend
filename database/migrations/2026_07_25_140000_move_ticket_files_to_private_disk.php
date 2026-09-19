<?php

use App\Models\TicketFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * انتقال پیوست‌های تیکت از دیسک عمومی به دیسک خصوصی.
 *
 * پیوست‌ها روی `public` ذخیره می‌شدند و از `/storage/ticket-files/...` بدون هیچ
 * احراز هویتی قابل دریافت بودند — در حالی که تیکت پشتیبانی حاوی فاکتور، تصویر
 * کارت ملی و مدارک بانکی است. همان قاعده‌ای که قبلاً رسیدهای مالی و فاکتورها را
 * به دیسک خصوصی برد، اینجا هم اعمال می‌شود.
 *
 * ایمنی:
 *   - fault-tolerant: شکستِ یک فایل کلِ مایگریشن (و دیپلوی) را متوقف نمی‌کند.
 *   - idempotent: فایلی که قبلاً منتقل شده دوباره کپی نمی‌شود.
 *   - non-destructive: نسخه‌ی عمومی *حذف نمی‌شود*. مسیر در DB عوض نمی‌شود (هر دو
 *     دیسک همان مسیر نسبی را دارند) و TicketFileController هر دو را می‌خواند،
 *     پس اگر کپی ناقص بماند هیچ پیوستی از دسترس صاحبش خارج نمی‌شود.
 *
 * پاک‌سازیِ نسخه‌های عمومی عمداً به این مایگریشن سپرده نشده — بعد از تأیید
 * موفقیتِ انتقال روی پروداکشن، دستی یا با یک دستور جدا انجام شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_files')) {
            return;
        }

        $private = Storage::disk(TicketFile::DISK);
        $public = Storage::disk('public');

        $moved = 0;
        $skipped = 0;

        DB::table('ticket_files')
            ->select(['id', 'path'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($private, $public, &$moved, &$skipped) {
                foreach ($rows as $row) {
                    $path = (string) $row->path;

                    if ($path === '' || $private->exists($path) || ! $public->exists($path)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $stream = $public->readStream($path);

                        if ($stream === null || $stream === false) {
                            $skipped++;

                            continue;
                        }

                        $private->writeStream($path, $stream);

                        if (is_resource($stream)) {
                            fclose($stream);
                        }

                        $moved++;
                    } catch (Throwable $e) {
                        $skipped++;
                        Log::warning('Ticket attachment relocation failed', [
                            'ticket_file_id' => $row->id,
                            'path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('Ticket attachments relocated to the private disk', [
            'moved' => $moved,
            'skipped' => $skipped,
        ]);
    }

    public function down(): void
    {
        // no-op عمدی: نسخه‌ی عمومی حذف نشده، پس چیزی برای بازگرداندن نیست.
        // برگرداندن فایل‌ها به دیسک عمومی یعنی بازتولیدِ همان نشتِ اطلاعات.
    }
};
