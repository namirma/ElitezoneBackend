<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مهلتِ بررسیِ درخواستِ تأمین‌کننده (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹: «مهلت تأیید ۴۸
 * ساعت است» و «به تأمین‌کننده نشان داده می‌شود»).
 *
 * ستون nullable است و فقط برای `price_change` پر می‌شود — دو نوعِ دیگر مهلتی
 * ندارند و گذاشتنِ یک مقدارِ ساختگی برایشان یعنی کرونِ انقضا شروع کند به بستنِ
 * درخواست‌هایی که هیچ‌کس برایشان مهلت تعیین نکرده بود.
 *
 * ایندکس روی `(status, review_deadline)` است چون کرون دقیقاً همین دو را با هم
 * فیلتر می‌کند: ردیف‌های باز که مهلتشان گذشته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_requests', function (Blueprint $table) {
            $table->timestamp('review_deadline')->nullable()->after('reviewed_at');
            $table->index(['status', 'review_deadline'], 'supplier_requests_status_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_requests', function (Blueprint $table) {
            $table->dropIndex('supplier_requests_status_deadline_idx');
            $table->dropColumn('review_deadline');
        });
    }
};
