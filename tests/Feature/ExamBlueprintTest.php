<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\User;
use App\Services\ExamBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExamBlueprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_matrix_distribution_and_coverage(): void
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'bp'.substr((string) Str::uuid(), 0, 6), 'full_name' => 'BP', 'role' => 'teacher', 'active' => true]);

        $examId = (string) Str::uuid();
        DB::table('exams')->insert([
            'id' => $examId, 'exam_code' => 'BP1', 'name' => 'BP', 'duration_minutes' => 30, 'passing_grade' => 50,
            'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'subject' => 'Physics',
            'created_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = [
            ['Kinematics', 'easy', 2.0],
            ['Kinematics', 'hard', 5.0],
            ['Dynamics', 'medium', 3.0],
        ];
        $pos = 0;
        foreach ($rows as [$topic, $diff, $pts]) {
            DB::table('exam_questions')->insert([
                'id' => (string) Str::uuid(), 'exam_id' => $examId, 'position' => ++$pos, 'type' => 'single_choice',
                'topic' => $topic, 'difficulty' => $diff, 'points' => $pts, 'prompt' => 'Q', 'created_at' => now(),
            ]);
        }

        // Two LO topics for this teacher+subject; only Kinematics is covered.
        foreach (['Kinematics', 'Thermodynamics'] as $t) {
            DB::table('learning_objectives')->insert([
                'id' => (string) Str::uuid(), 'subject' => 'Physics', 'topic' => $t, 'language' => 'English',
                'text' => 'obj '.$t, 'curriculum' => 'kurikulum_merdeka',
                'uploaded_by' => $teacher->id, 'created_at' => now(),
            ]);
        }

        $bp = ExamBlueprint::forExam(Exam::find($examId));

        $this->assertSame(3, $bp['totals']['count']);
        $this->assertSame(10.0, $bp['totals']['points']);
        $this->assertSame(2, $bp['totals']['topics']);

        // Matrix: Kinematics row has easy=1 and hard=1.
        $kin = collect($bp['matrix'])->firstWhere('topic', 'Kinematics');
        $this->assertSame(1, $kin['cells']['easy']['count']);
        $this->assertSame(1, $kin['cells']['hard']['count']);
        $this->assertSame(0, $kin['cells']['medium']['count']);

        // Distribution by difficulty: hard = 5/10 points = 50%.
        $hard = collect($bp['byDifficulty'])->firstWhere('key', 'hard');
        $this->assertSame(50.0, $hard['pct']);

        // Coverage: Thermodynamics is an LO topic with no question.
        $this->assertContains('Thermodynamics', $bp['uncoveredTopics']);
        $this->assertNotContains('Kinematics', $bp['uncoveredTopics']);
    }
}
