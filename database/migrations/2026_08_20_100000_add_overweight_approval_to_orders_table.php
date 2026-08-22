<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جریان تأیید دومرحله‌ای اضافه‌بار — بند ۳.۷ و ۸.۲ گام ۷الف سند.
 *
 * ⚠️ **این مهاجرت یک بن‌بست را باز می‌کند، نه یک قابلیت تازه.** پیش از این،
 * `ProcessWeighbridgeResultAction` سفارش را به `awaiting_weight_correction`
 * می‌برد ولی هیچ کدی آن را بیرون نمی‌آورد: نه اکشنی برای تأیید بود، نه روتی،
 * و `$weighableStatuses` هم این وضعیت را نمی‌پذیرفت — پس باسکولِ دوباره هم رد
 * می‌شد. سفارشی که اضافه‌بارش از تلورانس می‌گذشت **برای همیشه قفل می‌ماند**.
 *
 * چهار ستون، نه بیشتر: «تأییدشده» از هم‌زمانیِ دو مهر مشتق می‌شود
 * (`Order::isOverweightApproved()`) و درصدِ اضافه‌بار از خودِ دو ستونِ وزن،
 * پس هیچ‌کدام ستونِ جدا نمی‌خواهند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // مابه‌التفاوتی که خریدار باید بپذیرد. صفر یعنی درخواستی باز نیست.
            $t->decimal('overweight_pending_amount', 20, 2)->default(0)
                ->after('buyer_refund_pending_amount')
                ->comment('deltaPOO در انتظار تأیید دو طرف؛ هنوز هیچ لِگ مالی نخورده');

            // ترتیب اجباری است: اول تأمین‌کننده، بعد خریدار (بند ۳.۷).
            $t->timestamp('overweight_supplier_approved_at')->nullable()
                ->after('overweight_pending_amount');
            $t->timestamp('overweight_buyer_approved_at')->nullable()
                ->after('overweight_supplier_approved_at');

            // مهلت پاسخ خریدار؛ با گذشتنش محموله باطل و سفارش به processing برمی‌گردد.
            $t->timestamp('overweight_deadline')->nullable()
                ->after('overweight_buyer_approved_at');

            $t->index('overweight_deadline', 'orders_overweight_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex('orders_overweight_deadline_idx');
            $t->dropColumn([
                'overweight_pending_amount',
                'overweight_supplier_approved_at',
                'overweight_buyer_approved_at',
                'overweight_deadline',
            ]);
        });
    }
};
