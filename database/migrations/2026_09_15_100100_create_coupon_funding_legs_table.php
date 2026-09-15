<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «در سفارشِ X، از کیف پولِ Y، چقدر واقعاً بابتِ کوپن کم شد».
 *
 * منبعِ حقیقتِ سه پنل («تخفیفِ تأمین‌شده در سفارشِ …») و مبنای برگشتِ دقیقِ
 * لِگ‌ها در استرداد. اسنپ‌شاتِ نوع/کاربر/درصد را خودش نگه می‌دارد تا به
 * `coupon_funders` وابسته نباشد. `amount` ریالِ صحیح است — همان قاعده‌ی
 * `MoneyColumnsAreIntegerRialsTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_funding_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('coupon_funder_id')->nullable()->constrained('coupon_funders')->nullOnDelete();
            $table->string('funder_type', 16);
            $table->foreignId('funder_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('share_percent', 5, 2);
            $table->decimal('amount', 20, 0);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['funder_user_id', 'funder_type']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_funding_legs');
    }
};
