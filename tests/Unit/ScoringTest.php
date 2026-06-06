<?php

namespace Tests\Unit;

use App\Services\Scoring;
use Tests\TestCase;

class ScoringTest extends TestCase
{
    private function q(string $id, string $type, float $points = 1, string $topic = 'T'): array
    {
        return ['id' => $id, 'type' => $type, 'points' => $points, 'topic' => $topic];
    }

    public function test_single_choice_correct_and_wrong(): void
    {
        $questions = [$this->q('a', 'single_choice', 2)];
        $keys = ['a' => 'A'];

        $this->assertEqualsWithDelta(2.0, (float) Scoring::score($questions, $keys, ['a' => 'A'])['finalScore'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) Scoring::score($questions, $keys, ['a' => 'B'])['finalScore'], 0.001);
    }

    public function test_multi_select_partial_credit(): void
    {
        $questions = [$this->q('m', 'multi_select', 2)];
        $keys = ['m' => ['A', 'B']];

        // Half the correct options, no wrong picks → (1-0)/2 = 0.5 → 1.0 of 2.
        $this->assertEqualsWithDelta(1.0, (float) Scoring::score($questions, $keys, ['m' => ['A']])['finalScore'], 0.001);
        // One correct + one wrong → (1-1)/2 = 0.
        $this->assertEqualsWithDelta(0.0, (float) Scoring::score($questions, $keys, ['m' => ['A', 'C']])['finalScore'], 0.001);
    }

    public function test_numeric_exact_match(): void
    {
        $questions = [$this->q('n', 'numeric', 1)];
        $this->assertEqualsWithDelta(1.0, (float) Scoring::score($questions, ['n' => 3.14], ['n' => '3.14'])['finalScore'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) Scoring::score($questions, ['n' => 3.14], ['n' => '999'])['finalScore'], 0.001);
    }

    public function test_essay_pends_until_manually_graded(): void
    {
        $questions = [$this->q('e', 'essay', 10)];
        $keys = ['e' => ''];

        $r = Scoring::score($questions, $keys, ['e' => 'a written answer']);
        $this->assertSame(1, $r['pendingEssayCount']);
        $this->assertTrue($r['itemResults'][0]['requiresGrading']);

        $graded = Scoring::score($questions, $keys, ['e' => 'a written answer'], ['e' => 7]);
        $this->assertSame(0, $graded['pendingEssayCount']);
        $this->assertEqualsWithDelta(7.0, (float) $graded['finalScore'], 0.001);
    }

    public function test_percent_and_possible(): void
    {
        $questions = [$this->q('a', 'single_choice', 1), $this->q('b', 'single_choice', 1)];
        $keys = ['a' => 'A', 'b' => 'A'];
        $r = Scoring::score($questions, $keys, ['a' => 'A', 'b' => 'B']); // 1 of 2
        $this->assertEqualsWithDelta(2.0, (float) $r['possibleScore'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $r['percentScore'], 0.001);
    }
}
