<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ide_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ide_problem_id')->constrained()->cascadeOnDelete();
            $table->text('input');
            $table->text('expected_output');
            $table->boolean('is_hidden')->default(false);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ide_test_cases');
    }
};
