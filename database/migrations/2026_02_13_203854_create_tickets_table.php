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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('department_id')->constrained('departments');
            $table->foreignId('previous_department_id')->nullable()->constrained('departments');
            $table->foreignId('admin_id')->nullable();
            $table->string('subject');
            $table->text('message');
            $table->enum('status', ['pending', 'answered', 'closed'])->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
