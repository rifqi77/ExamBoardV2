<?php

namespace Tests\Unit;

use App\Http\Controllers\ExamTakeController;
use App\Models\Exam;
use App\Models\User;
use Tests\TestCase;

class ExtraTimeTest extends TestCase
{
    private function exam(int $minutes): Exam
    {
        $e = new Exam;
        $e->duration_minutes = $minutes;

        return $e;
    }

    private function student(?int $pct): User
    {
        $u = new User;
        if ($pct !== null) {
            $u->extra_time_percent = $pct;
        }

        return $u;
    }

    public function test_extra_time_extends_duration(): void
    {
        $exam = $this->exam(60);

        $this->assertSame(3600, ExamTakeController::durationSecondsFor($exam, $this->student(0)));
        $this->assertSame(4500, ExamTakeController::durationSecondsFor($exam, $this->student(25)));
        $this->assertSame(5400, ExamTakeController::durationSecondsFor($exam, $this->student(50)));
        $this->assertSame(7200, ExamTakeController::durationSecondsFor($exam, $this->student(100)));
        $this->assertSame(3600, ExamTakeController::durationSecondsFor($exam, $this->student(null))); // no accommodation
    }
}
