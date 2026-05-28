<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE questions MODIFY COLUMN type ENUM('mcq', 'multi_select', 'true_false', 'short_answer', 'numerical', 'comprehension') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE questions MODIFY COLUMN type ENUM('mcq', 'multi_select', 'true_false', 'short_answer', 'numerical') NOT NULL");
    }
};
