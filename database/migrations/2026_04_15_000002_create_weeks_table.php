<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->integer('week_number');
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['course_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weeks');
    }
};
