<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هزینه‌های عودت به مبدأ — فاز ۱۱، بند ۳.۱۱ و ۴.۸ سند.
 *
 * چرا جدول جدا و نه یک ستون روی `shipments`: تأمین‌کننده هزینه‌ها را **هر بار
 * جداگانه** ثبت می‌کند (عودت، حمل مجدد، انبارداری)، چرخه تکرارپذیر است، و
 * سقفِ بند ۳.۱۱ روی **مجموعِ** تأییدشده‌ها حساب می‌شود. یک ستون هیچ‌کدام را
 * نمی‌توانست نگه دارد.
 *
 * سه مهرِ زمانی و نه یک ستونِ `status`: جریان تأیید سه‌مرحله‌ای است
 * (تأمین‌کننده ثبت → بک‌آفیس تأیید → خریدار تأیید) و هر مرحله باید زمانش را
 * جداگانه نگه دارد — همان الگویی که `user_agreements` و دفترِ کیف پول دارند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('return_damages')) {
            return;
        }

        Schema::create('return_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 20, 2);
            $table->string('description');
            $table->timestamp('backoffice_approved_at')->nullable();
            $table->unsignedBigInteger('backoffice_approved_by')->nullable();
            $table->timestamp('buyer_approved_at')->nullable();
            $table->timestamp('settled_at')->nullable()
                ->comment('لحظه‌ی پرداخت خریدار → تأمین‌کننده');
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'buyer_approved_at'], 'return_damages_order_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_damages');
    }
};
