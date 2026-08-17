<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * زیرسیستم بدهی و اعتبار — فاز ۹، بند ۴.۷ سند.
 *
 * الگو عیناً از `wallets` + `wallet_transactions` گرفته شده: یک ستونِ مانده روی
 * `users` و یک دفترِ append-only. دلیلش این است که `financial:doctor` بتواند
 * مانده را از دفتر **بازپخش** کند و درز را پیدا کند — همان کاری که
 * `LedgerReconciler` برای کیف پول‌ها می‌کند. یک ستونِ تنها بدونِ دفتر، هر
 * اشتباهی را نامرئی می‌کرد.
 *
 * `credit_limit` پیش‌فرض صفر است، یعنی «هیچ اعتباری» — عمدی. اعتبار دادن باید
 * یک تصمیمِ صریحِ بک‌آفیس باشد (پاسخ مدیر، دور ۲ پرسش ۵)، نه چیزی که هر کاربر
 * تازه به‌صورت پیش‌فرض بگیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'debt_balance')) {
                $table->decimal('debt_balance', 20, 2)->default(0)->after('is_active')
                    ->comment('مجموع بدهی کاربر؛ فقط از طریق DebtEngine نوشته می‌شود');
            }

            if (! Schema::hasColumn('users', 'credit_limit')) {
                $table->decimal('credit_limit', 20, 2)->default(0)->after('debt_balance')
                    ->comment('سقف اعتبار؛ دستی توسط بک‌آفیس');
            }
        });

        if (Schema::hasTable('debt_entries')) {
            return;
        }

        Schema::create('debt_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->string('type', 30)
                ->comment('overweight | return_damage | weighbridge_deficit | repayment | adjustment');
            $table->decimal('amount', 20, 2)->comment('مثبت = ایجاد بدهی، منفی = بازپرداخت');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'debt_entries_user_created_idx');
            $table->index('order_id', 'debt_entries_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_entries');

        Schema::table('users', function (Blueprint $table) {
            foreach (['debt_balance', 'credit_limit'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
