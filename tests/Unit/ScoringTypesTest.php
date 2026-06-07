<?php

namespace Tests\Unit;

use App\Services\Scoring;
use PHPUnit\Framework\TestCase;

/**
 * Certifies the scoring of every supported question type — including the
 * partial-credit and tolerance rules that make the platform fair. These types
 * are authored manually, AI-generated, and answered in the exam UI; this test
 * locks their grading behaviour.
 */
class ScoringTypesTest extends TestCase
{
    /** Score a single question and return its item result + roll-ups. */
    private function one(string $type, $correct, $answer, float $points = 1.0, array $manual = []): array
    {
        $res = Scoring::score(
            [['id' => 'q', 'topic' => 't', 'points' => $points, 'type' => $type]],
            ['q' => $correct],
            ['q' => $answer],
            $manual
        );

        return $res['itemResults'][0] + ['pendingEssayCount' => $res['pendingEssayCount']];
    }

    public function test_single_choice_is_normalized_exact_match(): void
    {
        $this->assertSame(2.0, $this->one('single_choice', 'B', 'b', 2)['awarded']); // case-insensitive
        $this->assertSame(0.0, $this->one('single_choice', 'B', 'A', 2)['awarded']);
        $this->assertFalse($this->one('single_choice', 'B', null, 2)['isCorrect']);  // blank
    }

    public function test_multi_select_partial_credit(): void
    {
        $this->assertSame(4.0, $this->one('multi_select', ['A', 'C'], ['A', 'C'], 4)['awarded']); // all right
        $this->assertSame(2.0, $this->one('multi_select', ['A', 'C'], ['A'], 4)['awarded']);      // half right
        $this->assertSame(0.0, $this->one('multi_select', ['A', 'C'], ['A', 'B'], 4)['awarded']); // one right, one wrong → net 0
        $this->assertSame(0.0, $this->one('multi_select', ['A', 'C'], ['B', 'D'], 4)['awarded']); // all wrong
    }

    public function test_numeric_tolerance_bands(): void
    {
        $this->assertSame(1.0, $this->one('numeric', 100, 100, 1)['awarded']);  // exact
        $this->assertSame(0.8, $this->one('numeric', 100, 101, 1)['awarded']);  // within 1%
        $this->assertSame(0.5, $this->one('numeric', 100, 104, 1)['awarded']);  // within 5%
        $this->assertSame(0.2, $this->one('numeric', 100, 109, 1)['awarded']);  // within 10%
        $this->assertSame(0.0, $this->one('numeric', 100, 200, 1)['awarded']);  // way off
    }

    public function test_short_text_is_normalized(): void
    {
        $this->assertSame(1.0, $this->one('short_text', 'Paris', '  paris ', 1)['awarded']); // trim + case
        $this->assertSame(0.0, $this->one('short_text', 'Paris', 'London', 1)['awarded']);
    }

    public function test_essay_is_pending_until_graded(): void
    {
        $ungraded = $this->one('essay', null, 'my answer', 5);
        $this->assertTrue($ungraded['requiresGrading']);
        $this->assertSame(1, $ungraded['pendingEssayCount']);
        $this->assertSame(0.0, $ungraded['awarded']);

        $graded = $this->one('essay', null, 'my answer', 5, ['q' => 3]);
        $this->assertFalse($graded['requiresGrading']);
        $this->assertSame(0, $graded['pendingEssayCount']);
        $this->assertSame(3.0, $graded['awarded']);
    }
}
