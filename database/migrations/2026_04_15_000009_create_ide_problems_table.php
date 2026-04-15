<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ide_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->text('constraints')->nullable();
            $table->text('input_format')->nullable();
            $table->text('output_format')->nullable();
            $table->text('sample_input');
            $table->text('sample_output');
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->integer('week_number')->nullable();
            $table->string('language')->default('python');
            $table->integer('time_limit_seconds')->default(5);
            $table->integer('memory_limit_mb')->default(256);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ide_problems');
    }
};
