<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {

            $table->decimal('official_price', 18, 2)->nullable()->comment('OP: قیمت رسمی محصول');
            $table->decimal('weight_before_scale', 15, 3)->nullable()->comment('WBS: وزن قبل از باسکول');

            $table->decimal('commission_rate', 5, 2)->default(0)->comment('%TC: درصد کمیسیون');
            $table->decimal('owner_discount_rate', 5, 2)->default(0)->comment('%OW: درصد تخفیف خریدار');
            $table->decimal('saze_share_rate', 5, 2)->default(0)->comment('%SSG: درصد سهم سازه');
            $table->decimal('consultant_share_rate', 5, 2)->default(0)->comment('%CO: درصد سهم مشاور');
            $table->decimal('vat_rate', 5, 2)->default(0)->comment('%VAT: درصد ارزش افزوده');

            $table->decimal('payment_of_owner', 20, 2)->nullable()->comment('POO: پرداختی خریدار');
            $table->decimal('payment_to_supplier', 20, 2)->nullable()->comment('PTS: پرداختی به سوپلایر');
            $table->decimal('payment_to_consultant', 20, 2)->nullable()->comment('PTC: پرداختی به مشاور');

            $table->decimal('payment_to_reserve', 20, 2)->nullable()->comment('PTR: مبلغ پرداخت رزرو');
            $table->timestamp('validation_of_reserve')->nullable()->comment('VOR: تاریخ اعتبار رزرو');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'official_price', 'weight_before_scale',
                'commission_rate', 'owner_discount_rate', 'saze_share_rate',
                'consultant_share_rate', 'vat_rate',
                'payment_of_owner', 'payment_to_supplier', 'payment_to_consultant',
                'payment_to_reserve', 'validation_of_reserve',
            ]);
        });
    }
};
