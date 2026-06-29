<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the tickets table up to the helpdesk model described in the design spec
 * ("پروسه خرید" → بخش پشتیبانی / تیکتینگ):
 *
 *   - priority            اولویت تیکت (low|normal|high|urgent)
 *   - order_id            اتصال تیکت به سفارش
 *   - province_id/city_id استان و شهر تحویل
 *   - assigned_at         زمان «گرفتن»/تخصیص تیکت به ادمین
 *   - first_responded_at  برای KPI میانگین زمان اولین پاسخ
 *   - resolved_at/closed_at  برای KPI زمان حل و چرخه‌ی عمر
 *   - sla_due_at          مهلت SLA بر اساس اولویت (config/ticketing.php)
 *   - reopened_count      برای KPI نرخ بازگشایی
 *
 * Also widens `status` from a 3-value enum to VARCHAR so the full state machine
 * (open / in_progress / waiting_customer / waiting_supplier / answered / resolved /
 * closed) is storable. This additionally fixes a latent bug: the model and admin
 * controller already referenced Ticket::STATUS_OPEN ('open'), a value the old enum
 * did not allow — setting it would have failed under MySQL strict mode.
 *
 * Fully additive: existing rows keep their current status string unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('priority', 16)->default('normal')->after('status');
            $table->foreignId('order_id')->nullable()->after('user_id')->constrained('orders')->nullOnDelete();
            $table->unsignedBigInteger('province_id')->nullable()->after('order_id');
            $table->unsignedBigInteger('city_id')->nullable()->after('province_id');
            $table->timestamp('assigned_at')->nullable()->after('admin_id');
            $table->timestamp('first_responded_at')->nullable()->after('assigned_at');
            $table->timestamp('resolved_at')->nullable()->after('first_responded_at');
            $table->timestamp('closed_at')->nullable()->after('resolved_at');
            $table->timestamp('sla_due_at')->nullable()->after('closed_at');
            $table->unsignedInteger('reopened_count')->default(0)->after('sla_due_at');

            $table->index('status');
            $table->index('priority');
        });

        // Widen status enum → varchar so the full state machine is storable.
        // Existing values (pending/answered/closed) are preserved as plain strings.
        DB::statement("ALTER TABLE tickets MODIFY status VARCHAR(32) NOT NULL DEFAULT 'open'");
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropIndex(['status']);
            $table->dropIndex(['priority']);
            $table->dropColumn([
                'priority', 'province_id', 'city_id', 'assigned_at', 'first_responded_at',
                'resolved_at', 'closed_at', 'sla_due_at', 'reopened_count',
            ]);
        });

        DB::statement("ALTER TABLE tickets MODIFY status ENUM('pending','answered','closed') NOT NULL DEFAULT 'pending'");
    }
};
