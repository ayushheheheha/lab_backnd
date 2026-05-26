<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'xp')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('xp')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'xp')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('xp');
            });
        }
    }
};
