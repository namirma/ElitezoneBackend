<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_sms_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisor_id')->constrained('users')->cascadeOnDelete();
            $table->string('mobile', 11);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['advisor_id', 'mobile']);
            $table->index('advisor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_sms_invites');
    }
};
