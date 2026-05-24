<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_solutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('drive_file_id');
            $table->text('description')->nullable();
            $table->string('author')->nullable();
            $table->string('duration')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->json('chapters')->nullable();
            $table->boolean('is_pro')->default(false);
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_solutions');
    }
};
