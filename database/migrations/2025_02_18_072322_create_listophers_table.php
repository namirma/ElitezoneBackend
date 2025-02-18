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
            $table->integer('pos');
            $table->integer('shape');
            $table->decimal('length', 8, 2);
            $table->integer('size');
            $table->decimal('hook', 8, 2);
            $table->decimal('overlap', 8, 2);
            $table->integer('quantity');
            $table->decimal('length_total', 8, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listopher_items');
        Schema::dropIfExists('listophers');
    }
};
