<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Week;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        $courses = [
            ['name' => 'Computational Thinking', 'slug' => 'ct', 'icon' => '🧠', 'has_ide' => true, 'sort_order' => 1, 'description' => 'Learn algorithmic thinking and problem solving'],
            ['name' => 'Mathematics 1', 'slug' => 'maths1', 'icon' => '📐', 'has_ide' => false, 'sort_order' => 2, 'description' => 'Foundations of mathematics for data science'],
            ['name' => 'Mathematics 2', 'slug' => 'maths2', 'icon' => '📏', 'has_ide' => false, 'sort_order' => 3, 'description' => 'Advanced mathematics concepts'],
            ['name' => 'Statistics 1', 'slug' => 'stats1', 'icon' => '📊', 'has_ide' => false, 'sort_order' => 4, 'description' => 'Probability and descriptive statistics'],
            ['name' => 'Statistics 2', 'slug' => 'stats2', 'icon' => '📈', 'has_ide' => false, 'sort_order' => 5, 'description' => 'Inferential statistics and hypothesis testing'],
            ['name' => 'Python', 'slug' => 'python', 'icon' => '🐍', 'has_ide' => true, 'sort_order' => 6, 'description' => 'Python programming from basics to advanced'],
            ['name' => 'English', 'slug' => 'english', 'icon' => '📝', 'has_ide' => false, 'sort_order' => 7, 'description' => 'English communication and comprehension'],
            ['name' => 'Java', 'slug' => 'java', 'icon' => '☕', 'has_ide' => true, 'sort_order' => 8, 'description' => 'Java programming and object oriented concepts'],
            ['name' => 'PDSA', 'slug' => 'pdsa', 'icon' => '🌲', 'has_ide' => true, 'sort_order' => 9, 'description' => 'Programming Data Structures and Algorithms'],
        ];

        foreach ($courses as $courseData) {
            $course = Course::create($courseData);

            for ($w = 1; $w <= 12; $w++) {
                Week::create([
                    'course_id' => $course->id,
                    'week_number' => $w,
                    'title' => "Week $w",
                ]);
            }
        }
    }
}
