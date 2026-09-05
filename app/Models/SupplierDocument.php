<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مدرکِ پیوستِ درخواستِ تأمین‌کنندگی — پروانه‌ی کسب، گواهیِ استاندارد و مانند آن.
 *
 * فایل روی دیسکِ **خصوصیِ** `local` می‌نشیند نه `public`: این‌ها مدارکِ هویتی و
 * حقوقی‌اند و نباید با حدسِ نامِ فایل از اینترنت خوانده شوند — همان قاعده‌ای که
 * مدارکِ سوابقِ شغلی دارند.
 */
class SupplierDocument extends Model
{
    use HasFactory;

    /** دیسکِ خصوصی؛ در `storage:link` نیست و URL مستقیم ندارد. */
    public const DISK = 'local';

    public const DIRECTORY = 'supplier_documents';

    public const ID = 'id';

    public const SUPPLIER_PROFILE_ID = 'supplier_profile_id';

    public const TITLE = 'title';

    public const PATH = 'path';

    public const ORIGINAL_NAME = 'original_name';

    public const MIME_TYPE = 'mime_type';

    public const SIZE = 'size';

    protected $fillable = [
        self::SUPPLIER_PROFILE_ID,
        self::TITLE,
        self::PATH,
        self::ORIGINAL_NAME,
        self::MIME_TYPE,
        self::SIZE,
    ];

    protected $casts = [
        self::SIZE => 'integer',
    ];

    public function supplierProfile(): BelongsTo
    {
        return $this->belongsTo(SupplierProfile::class, self::SUPPLIER_PROFILE_ID);
    }
}
