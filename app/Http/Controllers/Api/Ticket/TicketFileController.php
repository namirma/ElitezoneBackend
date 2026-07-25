<?php

namespace App\Http\Controllers\Api\Ticket;

use App\Http\Controllers\Controller;
use App\Models\TicketFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * دانلود محافظت‌شدهٔ پیوست تیکت.
 *
 * پیوست‌ها تا پیش از این روی دیسک `public` می‌نشستند و `TicketFileResource`
 * یک URL مستقیم برمی‌گرداند — یعنی فاکتور، تصویر کارت ملی و مدارک بانکیِ داخل
 * تیکت برای هر کسی که لینک را داشت بدونِ لاگین قابل دریافت بود. حالا فایل روی
 * دیسک خصوصی است و فقط از این مسیر و پس از بررسی دسترسی سرو می‌شود.
 *
 * قاعدهٔ دسترسی همان `CanSeeTicket` است: صاحبِ تیکت یا ادمین. اینجا به‌جای
 * میدل‌ور، دستی بررسی می‌شود چون پارامترِ روت خودِ فایل است نه تیکت، و تیکتِ
 * مرجع باید از روی فایل (پیام اول یا پاسخ) resolve شود.
 */
class TicketFileController extends Controller
{
    public function download(TicketFile $ticketFile): StreamedResponse|JsonResponse
    {
        $ticket = $ticketFile->resolveTicket();
        $user = Auth::user();

        if (! $ticket || ! $user) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ((int) $ticket->user_id !== (int) $user->id && ! $user->hasRole('admin')) {
            return error(__('auth.forbidden'), [], 403);
        }

        $path = $ticketFile->{TicketFile::PATH};

        // رکوردهای قدیمی ممکن است هنوز به دیسک public اشاره کنند (پیش از
        // مایگریشنِ جابه‌جایی). هر دو دیسک بررسی می‌شود تا پیوست‌های قبلی
        // برای صاحبشان در دسترس بماند.
        $disk = collect([TicketFile::DISK, 'public'])
            ->first(fn (string $candidate) => $path && Storage::disk($candidate)->exists($path));

        if (! $disk) {
            return error(__('ticket.file_not_found'), [], 404);
        }

        return Storage::disk($disk)->download(
            $path,
            $ticketFile->{TicketFile::ORIGINAL_NAME} ?: basename($path),
        );
    }
}
