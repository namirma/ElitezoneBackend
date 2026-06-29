<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            // relative path on the public disk (storage/app/public/avatars/…); the
            // Resource exposes the full URL via Storage::disk('public')->url().
            $table->string('avatar')->nullable()->after('family');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
