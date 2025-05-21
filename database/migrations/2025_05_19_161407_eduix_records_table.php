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
        Schema::create('eduix_records', function (Blueprint $table) {
            $table->id();
            // کلید ترکیبی یکتا برای جلوگیری از رکورد تکراری
            $table->string('national_code')->index();
            $table->string('trace_code')->index();

            // اطلاعات فردی (همه رشته)
            $table->string('name');
            $table->string('family');
            $table->string('father_name')->nullable();
            $table->string('id_number')->nullable();
            $table->string('birth_date')->nullable();

            // اطلاعات تحصیلی (همه رشته)
            $table->string('university')->nullable();
            $table->string('field')->nullable();
            $table->string('course_type')->nullable();
            $table->string('entry_date')->nullable();
            $table->string('graduate_date')->nullable();
            $table->string('gpa')->nullable();
            $table->string('status')->nullable();

            // زمان‌بندی
            $table->timestamps();

            // تضمین یکتایی
            $table->unique(['national_code', 'trace_code'], 'eduix_unique_record');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eduix_records');
    }
};
