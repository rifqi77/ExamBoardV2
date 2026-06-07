<?php

namespace Tests\Feature;

use App\Http\Controllers\ExamImportController;
use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExamImportTest extends TestCase
{
    use RefreshDatabase;

    private function req(User $actor, array $package): Request
    {
        $r = Request::create('/api/teacher/exams/import', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($package));
        $r->attributes->set('authUser', $actor);

        return $r;
    }

    public function test_imports_package_creates_exam_questions_and_bank_mirror(): void
    {
        // An admin must exist (bank "owner"); the teacher does the import.
        User::create(['id' => (string) Str::uuid(), 'username' => 'adm'.substr((string) Str::uuid(), 0, 5), 'full_name' => 'Adm', 'role' => 'admin', 'active' => true]);
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'imp'.substr((string) Str::uuid(), 0, 5), 'full_name' => 'Imp', 'role' => 'teacher', 'active' => true]);

        $package = [
            'metadata' => [
                'examCode' => 'IMP-1', 'name' => 'Imported Exam', 'durationMinutes' => 45,
                'passingGrade' => 60, 'generalInstructions' => 'Answer all questions.',
                'examMode' => 'strict', 'language' => 'English', 'subject' => 'Physics',
            ],
            'questions' => [
                ['position' => 1, 'type' => 'single_choice', 'topic' => 'Kinematics', 'points' => 2,
                    'prompt' => 'Pick A', 'options' => [['id' => 'A', 'text' => 'Alpha'], ['id' => 'B', 'text' => 'Beta']],
                    'correctAnswer' => 'A', 'explanationText' => 'A is right'],
                ['position' => 2, 'type' => 'essay', 'topic' => 'Dynamics', 'points' => 10,
                    'prompt' => 'Explain Newton 2', 'options' => null, 'correctAnswer' => null, 'explanationText' => 'F=ma'],
                ['position' => 3, 'type' => 'single_choice', 'topic' => 'Bad', 'points' => 0, // invalid → skipped
                    'prompt' => 'x', 'options' => [], 'correctAnswer' => 'A', 'explanationText' => ''],
            ],
            'media' => [],
        ];

        $resp = (new ExamImportController())->import($this->req($teacher, $package));
        $body = json_decode($resp->getContent(), true);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('IMP-1', $body['examId']);
        $this->assertSame(2, $body['questionsCreated']);          // 3rd skipped (0 points)
        $this->assertNotEmpty($body['warnings']);                 // warned about the skip

        $exam = Exam::where('exam_code', 'IMP-1')->first();
        $this->assertNotNull($exam);
        $this->assertSame(2, ExamQuestion::where('exam_id', $exam->id)->count());
        $this->assertSame(2, BankQuestion::where('uploaded_by', $teacher->id)->count()); // mirrored to bank

        // Single-choice correct answer normalized to the option id.
        $q1 = ExamQuestion::where('exam_id', $exam->id)->where('position', 1)->first();
        $this->assertSame('A', $q1->correct_answer);
    }

    public function test_duplicate_exam_code_is_rejected(): void
    {
        User::create(['id' => (string) Str::uuid(), 'username' => 'ad2'.substr((string) Str::uuid(), 0, 5), 'full_name' => 'Adm', 'role' => 'admin', 'active' => true]);
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'im2'.substr((string) Str::uuid(), 0, 5), 'full_name' => 'Imp', 'role' => 'teacher', 'active' => true]);
        $pkg = [
            'metadata' => ['examCode' => 'DUP-1', 'name' => 'Dup', 'durationMinutes' => 30, 'passingGrade' => 50, 'generalInstructions' => 'Instructions here.'],
            'questions' => [['position' => 1, 'type' => 'essay', 'topic' => 'T', 'points' => 5, 'prompt' => 'Write', 'options' => null, 'correctAnswer' => null, 'explanationText' => '']],
        ];
        $this->assertSame(200, (new ExamImportController())->import($this->req($teacher, $pkg))->getStatusCode());
        $this->assertSame(409, (new ExamImportController())->import($this->req($teacher, $pkg))->getStatusCode());
    }
}
