<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ستون tickets.status در مایگریشنِ اولیه فقط ('pending','answered','closed') را می‌پذیرفت،
 * اما مدل App\Models\Ticket پنج وضعیتِ دیگر هم دارد (open, in_progress, waiting_customer,
 * waiting_supplier, resolved). نوشتنِ هر یک از این پنج مقدار روی MySQL خطای truncation
 * می‌داد (یا در حالت non-strict مقدار '' درج می‌کرد) و عملاً چرخهٔ کاملِ تیکتینگ را
 * در سطح schema مسدود می‌کرد. این مایگریشن enum را به مجموعهٔ کاملِ Ticket::STATUSES گسترش می‌دهد.
 */
return new class extends Migration
{
    private const FULL = "ENUM('pending','open','in_progress','waiting_customer','waiting_supplier','answered','resolved','closed') NOT NULL DEFAULT 'pending'";

    private const ORIGINAL = "ENUM('pending','answered','closed') NOT NULL DEFAULT 'pending'";

    public function up(): void
    {
        // فقط روی MySQL/MariaDB معنا دارد (پروژه روی MySQL اجرا می‌شود).
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `tickets` MODIFY `status` '.self::FULL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // بازگردانی به enum اولیه. اگر رکوردی با وضعیت‌های جدید وجود داشته باشد،
        // پیش از rollback باید به یکی از سه مقدار اصلی نگاشت شود.
        DB::statement('ALTER TABLE `tickets` MODIFY `status` '.self::ORIGINAL);
    }
};
