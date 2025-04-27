<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('listophers', function (Blueprint $table) {
            $table->id();
            $table->string('project_code')->index();
            $table->timestamps();
        });

        Schema::create('listopher_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listopher_id')->constrained()->onDelete('cascade');
            $table->string('pos', 64);
            $table->string('shape', 64);
            $table->decimal('length');
            $table->string('size', 64);
            $table->decimal('hook')->nullable();
            $table->decimal('overlap')->nullable();
            $table->integer('quantity');
            $table->decimal('length_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listopher_items');
        Schema::dropIfExists('listophers');
    }
};
