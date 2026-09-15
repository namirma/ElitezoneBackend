<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تأمینِ تخفیفِ کوپن از سه منبع — `docs/specs/COUPON_FUNDING_2026-09-15.md`.
 *
 * هر ردیف می‌گوید «چه کسی چند درصدِ تخفیف را می‌دهد و آیا پذیرفته».
 * `funder_user_id` برای `company` همیشه NULL است (کیف پولِ سازه از config می‌آید).
 *
 * مهاجرتِ داده: هر کوپنِ موجود یک ردیفِ «شرکت ۱۰۰٪ / پذیرفته» می‌گیرد — دقیقاً
 * همان چیزی که تا امروز بی‌صدا بود (کلِ تخفیف از کیف پولِ سازه).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('is_active')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('coupon_funders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('funder_type', 16);
            $table->foreignId('funder_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->decimal('share_percent', 5, 2);
            $table->string('status', 16)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['coupon_id', 'funder_type', 'funder_user_id'], 'coupon_funders_unique');
            $table->index(['funder_user_id', 'status']);
            $table->index(['funder_type', 'status']);
        });

        $now = now();

        DB::table('coupons')->orderBy('id')->chunkById(500, function ($coupons) use ($now) {
            DB::table('coupon_funders')->insert(
                $coupons->map(fn ($c) => [
                    'coupon_id' => $c->id,
                    'funder_type' => 'company',
                    'funder_user_id' => null,
                    'share_percent' => 100,
                    'status' => 'accepted',
                    'responded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_funders');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
