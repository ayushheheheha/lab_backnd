<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['mcq', 'multi_select', 'true_false', 'short_answer', 'numerical']);
            $table->text('stem');
            $table->string('stem_image')->nullable();
            $table->text('stem_code')->nullable();
            $table->string('stem_code_language')->nullable()->default('plaintext');
            $table->text('explanation')->nullable();
            $table->decimal('marks', 5, 2)->default(1.00);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->integer('position')->default(0);
            $table->decimal('numerical_answer', 15, 6)->nullable();
            $table->decimal('numerical_tolerance', 15, 6)->default(0.01);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
