<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 40)->nullable();
            $table->string('title');
            $table->text('body');
            $table->boolean('is_anonymous')->default(false);
            $table->foreignId('linked_quiz_id')->nullable()->constrained('quizzes')->nullOnDelete();
            $table->unsignedBigInteger('accepted_reply_id')->nullable();
            $table->boolean('is_solved')->default(false);
            $table->integer('vote_count')->default(0);
            $table->integer('reply_count')->default(0);
            $table->integer('view_count')->default(0);
            $table->timestamps();

            $table->index('subject');
            $table->index(['course_id', 'created_at']);
            $table->index(['is_solved', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussions');
    }
};
