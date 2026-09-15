<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ورودِ دومرحله‌ای با TOTP (تصمیمِ مدیریت شماره‌ی ۸).
 *
 * یک ردیف به‌ازای هر کاربر. `secret` با castِ `encrypted` ذخیره می‌شود (نه هش —
 * برای تولیدِ کد باید خوانده شود). `enabled_at` تا وقتی کاربر اولین کد را تأیید
 * نکرده null می‌ماند؛ ردیفِ تأییدنشده در ورود بی‌اثر است. `recovery_codes` هشِ
 * SHA-256 ده کدِ یک‌بارمصرف است (کدها ۵۰ بیت آنتروپی دارند، هشِ کند لازم نیست)
 * و با هر مصرف یکی حذف می‌شود. `last_used_step` گامِ آخرین کدِ پذیرفته‌شده است
 * تا همان کد در پنجره‌ی ±۱ دوباره پذیرفته نشود (RFC 6238 §5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_two_factor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('enabled_at')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_two_factor');
    }
};
