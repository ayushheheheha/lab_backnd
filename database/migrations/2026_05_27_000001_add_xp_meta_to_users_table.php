<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_xp_action_date')) {
                $table->date('last_xp_action_date')->nullable()->after('xp');
            }
            if (!Schema::hasColumn('users', 'highest_level_reached')) {
                $table->unsignedTinyInteger('highest_level_reached')->default(1)->after('last_xp_action_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_xp_action_date')) {
                $table->dropColumn('last_xp_action_date');
            }
            if (Schema::hasColumn('users', 'highest_level_reached')) {
                $table->dropColumn('highest_level_reached');
            }
        });
    }
};
