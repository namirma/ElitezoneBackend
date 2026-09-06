<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('factory_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->onDelete('cascade');
            // کلیدِ آیکون از یک کتابخانه‌ی ثابتِ سمتِ فرانت (نه آپلودِ آزاد) —
            // هماهنگ با طراحیِ فیگما؛ مقادیرِ مجاز در FactoryFeatureRequest اعتبارسنجی می‌شود.
            $table->string('icon_key');
            $table->string('label');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factory_features');
    }
};
