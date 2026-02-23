<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class DocumentSequenceService
{
    // =========================================================================
    //  Constants (Types & Contexts) طبق داکیومنت شما
    // =========================================================================
    public const TYPE_ORDER   = 'ORD';
    public const TYPE_QUOTE   = 'QTE';
    public const TYPE_INVOICE = 'INV';
    public const TYPE_TICKET  = 'TCK';
    public const TYPE_WALLET  = 'WAL';

    public const CTX_REBAR    = 'RB';
    public const CTX_BRICK    = 'BR';

    public const CTX_TICKET_SUPPORT   = 'SUP';
    public const CTX_TICKET_SALES     = 'SAL';
    public const CTX_TICKET_FINANCIAL = 'FIN';
    public const CTX_TICKET_TECHNICAL = 'TEC';
    public const CTX_TICKET_COMPLAINT = 'CMP';
    public const CTX_TICKET_GENERAL   = 'GEN';

    /**
     * تولید شماره یکتا و هوشمند برای اسناد
     * * @param string $type نوع سند (مثلا ORD)
     * @param string $ctx کانتکست (مثلا RB)
     * @param string $modelClass مدل دیتابیس (مثلا Order::class)
     * @param string $column نام ستون در دیتابیس (مثلا order_number)
     * @return string
     */
    public function generate(string $type, string $ctx, string $modelClass, string $column): string
    {
        
        $yearMonth = verta()->format('Ym');

        // ۲. ساخت پیشوند ثابت این ماه
        // مثال: EZ-ORD-RB-140502-703
        $prefix = "EZ-{$type}-{$ctx}-{$yearMonth}-703";

        // ۳. کوئری امن به دیتابیس برای پیدا کردن آخرین شماره در این ماه
        // نکته مهم: lockForUpdate() باعث می‌شود اگر دو نفر همزمان کلیک کردند، نفر دوم منتظر بماند تا شماره اولی ثبت شود (جلوگیری از تکرار)
        $lastRecord = $modelClass::where($column, 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderBy($column, 'desc')
            ->first();

        // ۴. تعیین بخش ۵ رقمی متغیر (SEQ)
        if (!$lastRecord) {
            // اگر در این ماه هیچ سندی ثبت نشده باشد، از 100 شروع می‌کنیم
            $nextSequence = 100;
        } else {
            // استخراج ۵ رقم آخر از آخرین سند ثبت شده در این ماه
            $lastNumber = $lastRecord->{$column};
            $lastSequenceStr = substr($lastNumber, -5);

            // یکی به آن اضافه می‌کنیم
            $nextSequence = (int)$lastSequenceStr + 1;
        }

        // ۵. فرمت کردن به عدد ۵ رقمی (مثلاً 100 تبدیل می‌شود به 00100)
        $formattedSequence = str_pad((string)$nextSequence, 5, '0', STR_PAD_LEFT);

        // ۶. خروجی نهایی: EZ-ORD-RB-140502-70300100
        return "{$prefix}{$formattedSequence}";
    }
}
