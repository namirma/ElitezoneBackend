<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مدارکِ پیوستِ درخواستِ تأمین‌کنندگی.
 *
 * ## چرا لازم بود
 *
 * فرمِ درخواست فقط متن می‌گرفت. `standards` یک رشته‌ی آزاد بود که ادمین قرار
 * بود «تأییدش» کند بدونِ اینکه هیچ مدرکی برای دیدن داشته باشد — یعنی صفِ
 * پذیرش عملاً روی ادعا تصمیم می‌گرفت.
 *
 * ## دو قاعده
 *
 * ۱. **بیرونِ `role.supplier`.** مدرک را متقاضی می‌فرستد، یعنی کسی که هنوز
 *    نقش ندارد. گذاشتنش پشتِ آن میان‌افزار یعنی فقط پذیرفته‌شده‌ها بتوانند
 *    مدرک بدهند — دقیقاً برعکسِ کاری که باید بکند.
 * ۲. **دیسکِ خصوصی.** پروانه‌ی کسب و کارتِ ملی نباید با حدسِ نامِ فایل خوانده
 *    شوند؛ دانلود فقط از همین مسیرِ احرازشده و فقط برای صاحبش (و ادمین).
 */
class SupplierDocumentController extends Controller
{
    /** سقفِ تعدادِ مدرک، تا آپلود تبدیل به انبارِ فایل نشود. */
    private const MAX_DOCUMENTS = 10;

    private function profile(): SupplierProfile
    {
        $profile = SupplierProfile::where(SupplierProfile::USER_ID, Auth::id())->first();

        if (! $profile) {
            throw new BusinessException(__('supplier.documents.no_profile'), 404);
        }

        return $profile;
    }

    public function index(): JsonResponse
    {
        $documents = $this->profile()->documents()->latest(SupplierDocument::ID)->get();

        return success($documents->map(fn (SupplierDocument $d) => self::present($d))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            // نوعِ مدرک (§۴-۱ اسپکِ یکپارچه‌سازی) — «آگهی تأسیس» برای شخصِ حقوقی
            // شرطِ ارسالِ درخواست است، پس ادمین باید بداند کدام فایل است.
            'kind' => ['sometimes', 'in:'.implode(',', SupplierDocument::KINDS)],
            // ‏svg عمداً نیست — همان قاعده‌ی سراسریِ آپلودها. اینجا دیسک خصوصی
            // است ولی فهرست را یکسان نگه می‌داریم تا استثنا لازم نشود توضیح داد.
            // xlsx/xls/csv برای «فهرستِ محصولات» لازم شد؛ فهرست عمداً یکی است.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv', 'max:5120'],
        ], [
            'file.required' => __('supplier.documents.file_required'),
            'file.mimes' => __('supplier.documents.file_type'),
            'file.max' => __('supplier.documents.file_too_large'),
        ]);

        $profile = $this->profile();

        if ($profile->documents()->count() >= self::MAX_DOCUMENTS) {
            return error(__('supplier.documents.too_many', ['max' => self::MAX_DOCUMENTS]), [], 422);
        }

        $file = $request->file('file');
        $path = $file->store(SupplierDocument::DIRECTORY, SupplierDocument::DISK);

        $document = $profile->documents()->create([
            SupplierDocument::TITLE => $data['title'],
            SupplierDocument::KIND => $data['kind'] ?? SupplierDocument::KIND_OTHER,
            SupplierDocument::PATH => $path,
            SupplierDocument::ORIGINAL_NAME => $file->getClientOriginalName(),
            SupplierDocument::MIME_TYPE => $file->getMimeType() ?? $file->getClientMimeType(),
            SupplierDocument::SIZE => $file->getSize(),
        ]);

        return created(self::present($document), __('supplier.documents.uploaded'));
    }

    public function destroy(SupplierDocument $document): JsonResponse
    {
        $this->assertOwned($document);

        // فایل پیش از ردیف می‌رود: ردیفِ حذف‌شده با فایلِ باقی‌مانده یعنی
        // مدرکِ هویتیِ یتیم روی دیسک که دیگر هیچ‌کس نمی‌داند مالِ کیست.
        if (Storage::disk(SupplierDocument::DISK)->exists($document->{SupplierDocument::PATH})) {
            Storage::disk(SupplierDocument::DISK)->delete($document->{SupplierDocument::PATH});
        }

        $document->delete();

        return success(null, __('supplier.documents.deleted'));
    }

    public function download(SupplierDocument $document): StreamedResponse
    {
        $this->assertOwned($document);

        $path = $document->{SupplierDocument::PATH};

        if (! Storage::disk(SupplierDocument::DISK)->exists($path)) {
            throw new BusinessException(__('supplier.documents.file_missing'), 404);
        }

        return Storage::disk(SupplierDocument::DISK)->download(
            $path,
            $document->{SupplierDocument::ORIGINAL_NAME}
        );
    }

    /** صاحبِ مدرک، یا ادمین (که صفِ پذیرش را بررسی می‌کند). */
    private function assertOwned(SupplierDocument $document): void
    {
        $user = Auth::user();

        if ($user?->hasRole('admin')) {
            return;
        }

        $ownerUserId = $document->supplierProfile?->{SupplierProfile::USER_ID};

        if ((int) $ownerUserId !== (int) Auth::id()) {
            throw new BusinessException(__('global.forbidden'), 403);
        }
    }

    /** @return array<string, mixed> */
    public static function present(SupplierDocument $document): array
    {
        return [
            'id' => $document->getKey(),
            SupplierDocument::TITLE => $document->{SupplierDocument::TITLE},
            SupplierDocument::KIND => $document->{SupplierDocument::KIND},
            SupplierDocument::ORIGINAL_NAME => $document->{SupplierDocument::ORIGINAL_NAME},
            SupplierDocument::MIME_TYPE => $document->{SupplierDocument::MIME_TYPE},
            SupplierDocument::SIZE => $document->{SupplierDocument::SIZE},
            // هرگز `path` برنمی‌گردد: مسیرِ دیسکِ خصوصی نباید به کلاینت برسد.
            'download_url' => route('supplier-document.download', ['document' => $document->getKey()], false),
            'created_at' => $document->created_at,
        ];
    }
}
