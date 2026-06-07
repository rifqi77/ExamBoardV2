<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CohortMastery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CohortMasteryTest extends TestCase
{
    use RefreshDatabase;

    private function submission(string $examId, string $userId, array $topicBreakdown): void
    {
        DB::table('exam_submissions')->insert([
            'id' => (string) Str::uuid(), 'exam_id' => $examId, 'user_id' => $userId, 'username' => 'x', 'full_name' => 'X',
            'exam_name' => 'M', 'exam_mode' => 'strict', 'attempt' => 1, 'passing_grade' => 50,
            'final_score' => 0, 'possible_score' => 20, 'percent_score' => 0, 'passed' => 0, 'pending_essay_count' => 0,
            'answers_snapshot' => json_encode([]), 'topic_breakdown' => json_encode($topicBreakdown), 'submitted_at' => now(),
        ]);
    }

    public function test_mastery_aggregates_across_exam_weakest_first(): void
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'cm'.substr((string) Str::uuid(), 0, 6), 'full_name' => 'CM', 'role' => 'teacher', 'active' => true]);
        $s1 = User::create(['id' => (string) Str::uuid(), 'username' => 'c1'.substr((string) Str::uuid(), 0, 4), 'full_name' => 'C1', 'role' => 'student', 'active' => true]);
        $s2 = User::create(['id' => (string) Str::uuid(), 'username' => 'c2'.substr((string) Str::uuid(), 0, 4), 'full_name' => 'C2', 'role' => 'student', 'active' => true]);

        $examId = (string) Str::uuid();
        DB::table('exams')->insert([
            'id' => $examId, 'exam_code' => 'CM1', 'name' => 'M', 'duration_minutes' => 30, 'passing_grade' => 50,
            'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_by' => $teacher->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->submission($examId, $s1->id, [
            ['topic' => 'Algebra', 'earned' => 8, 'possible' => 10],
            ['topic' => 'Geometry', 'earned' => 3, 'possible' => 10],
        ]);
        $this->submission($examId, $s2->id, [
            ['topic' => 'Algebra', 'earned' => 6, 'possible' => 10],
            ['topic' => 'Geometry', 'earned' => 2, 'possible' => 10],
        ]);

        $out = CohortMastery::forScope($teacher);

        $this->assertSame(1, $out['summary']['exams']);
        $this->assertSame(2, $out['summary']['submissions']);
        $this->assertSame(2, $out['summary']['topics']);

        // Weakest first → Geometry (5/20 = 25%) before Algebra (14/20 = 70%).
        $this->assertSame('Geometry', $out['topics'][0]['topic']);
        $this->assertSame(25.0, $out['topics'][0]['masteryPercent']);
        $this->assertSame(2, $out['topics'][0]['studentsBelow']);          // both below 50%
        $this->assertSame('Algebra', $out['topics'][1]['topic']);
        $this->assertSame(70.0, $out['topics'][1]['masteryPercent']);
        $this->assertSame(0, $out['topics'][1]['studentsBelow']);
    }
}
