<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE quizzes MODIFY COLUMN section ENUM('practice', 'practice_graded', 'quiz1', 'quiz2', 'endterm', 'mock_test') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE quizzes MODIFY COLUMN section ENUM('practice', 'quiz1', 'quiz2', 'endterm') NOT NULL");
    }
};
