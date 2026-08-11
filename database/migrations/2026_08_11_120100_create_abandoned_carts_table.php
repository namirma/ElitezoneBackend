<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اسنپ‌شاتِ سبدهایی که مهلتشان تمام شد — بند ۳.۱ و ۴.۲.
 *
 * وقتی سبد منقضی می‌شود حذفش می‌کنیم، ولی پیش از حذف کاملش را اینجا می‌نویسیم.
 * دلیلش گزارشِ بک‌آفیس (فاز ۱۳) است: «چه چیزی در سبد بود که خریده نشد» تنها
 * سیگنالِ ازدست‌رفتنِ فروش است و با `DELETE` برای همیشه از بین می‌رفت.
 *
 * `items` عمداً json و denormalized است، نه FK به `product_variants`: این یک
 * اسنپ‌شاتِ تاریخی است و باید حتی پس از حذفِ واریانت یا تغییرِ قیمت هم همان
 * چیزی را نشان دهد که کاربر آن لحظه دیده بود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('abandoned_carts')) {
            return;
        }

        Schema::create('abandoned_carts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('supplier_user_id')->nullable();
            $t->json('items')->comment('اسنپ‌شات کامل اقلام: variant, factory, location, quantities, weight, price');
            $t->decimal('total_amount', 20, 2)->default(0);
            $t->string('coupon_code', 100)->nullable();
            // nullable چون MySQL فقط به **یک** ستونِ TIMESTAMP اجازه‌ی
            // auto-init می‌دهد و دومی در strict mode با
            // «Invalid default value» رد می‌شود. هر دو را همیشه
            // `CartParkService::snapshot()` پر می‌کند.
            $t->timestamp('cart_created_at')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'expired_at'], 'abandoned_carts_user_expired_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_carts');
    }
};
