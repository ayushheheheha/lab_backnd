<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ide_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ide_problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language');
            $table->longText('code');
            $table->enum('status', ['accepted', 'wrong_answer', 'runtime_error', 'time_limit_exceeded', 'pending']);
            $table->json('test_results')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->index(['ide_problem_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ide_submissions');
    }
};
