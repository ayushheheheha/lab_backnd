<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_solutions', function (Blueprint $table) {
            // 'google_drive' (existing behaviour) or 'youtube'
            $table->string('provider', 20)->default('google_drive')->after('title');
            // For YouTube-hosted videos. Drive videos keep using drive_file_id.
            $table->string('youtube_id', 40)->nullable()->after('drive_file_id');
        });

        // Drive id is no longer required — YouTube videos won't have one.
        Schema::table('video_solutions', function (Blueprint $table) {
            $table->string('drive_file_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('video_solutions', function (Blueprint $table) {
            $table->dropColumn(['provider', 'youtube_id']);
        });
    }
};
