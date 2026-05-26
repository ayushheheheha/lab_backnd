<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_endorsed')->default(false);
            $table->integer('vote_count')->default(0);
            $table->timestamps();

            $table->index(['discussion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_replies');
    }
};
