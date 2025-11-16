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
            $table->string('national_code')->index();
            $table->string('trace_code')->index();
            $table->string('name');
            $table->string('family');
            $table->string('father_name')->nullable();
            $table->string('id_number')->nullable();
            $table->string('birth_date')->nullable();
            $table->string('university')->nullable();
            $table->string('field')->nullable();
            $table->string('course_type')->nullable();
            $table->string('entry_date')->nullable();
            $table->string('graduate_date')->nullable();
            $table->string('gpa')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->unique(['national_code', 'trace_code'], 'eduix_unique_record');
            $table->enum('admin_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable(); 
            $table->unique(['user_id', 'trace_code'], 'user_eduix_unique_record');
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
