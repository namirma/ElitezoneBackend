<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\EduixRecord;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('eduix_records', function (Blueprint $table) {
            $table->foreignId(EduixRecord::USER_ID)
                  ->after(EduixRecord::ID)
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->enum(EduixRecord::ADMIN_STATUS, [
                    EduixRecord::STATUS_PENDING,
                    EduixRecord::STATUS_APPROVED,
                    EduixRecord::STATUS_REJECTED
                  ])
                  ->after(EduixRecord::STATUS)
                  ->default(EduixRecord::STATUS_PENDING);

            $table->text(EduixRecord::REJECTION_REASON)
                  ->after(EduixRecord::ADMIN_STATUS)
                  ->nullable();

            $table->dropUnique('eduix_unique_record');
            $table->unique([EduixRecord::USER_ID, EduixRecord::TRACE_CODE], 'user_eduix_unique_record');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eduix_records', function (Blueprint $table) {
            $table->dropUnique('user_eduix_unique_record');
            $table->unique(['national_code', 'trace_code'], 'eduix_unique_record');

            $table->dropColumn(EduixRecord::REJECTION_REASON);
            $table->dropColumn(EduixRecord::ADMIN_STATUS);
            $table->dropForeign([EduixRecord::USER_ID]);
            $table->dropColumn(EduixRecord::USER_ID);
        });
    }
};
