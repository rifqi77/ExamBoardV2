<?php

namespace Tests\Unit;

use App\Services\GradingMetrics;
use App\Services\GradingStats;
use Tests\TestCase;

class GradingTest extends TestCase
{
    public function test_identical_text_scores_full(): void
    {
        $t = 'photosynthesis converts light energy into chemical energy';
        $this->assertEqualsWithDelta(1.0, GradingMetrics::rouge1($t, $t), 0.001);
        $this->assertEqualsWithDelta(1.0, GradingMetrics::rougeL($t, $t), 0.001);
        $this->assertGreaterThan(0.95, GradingMetrics::bleu($t, $t));
    }

    public function test_partial_overlap_rouge(): void
    {
        // 2 of 3 unigrams overlap; LCS = "the cat" (2) → both F1 = 2/3.
        $this->assertEqualsWithDelta(0.6667, GradingMetrics::rouge1('the cat sat', 'the cat ran'), 0.001);
        $this->assertEqualsWithDelta(0.6667, GradingMetrics::rougeL('the cat sat', 'the cat ran'), 0.001);
    }

    public function test_disjoint_text_scores_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, GradingMetrics::rouge1('alpha beta gamma', 'delta epsilon zeta'), 0.001);
        $this->assertEqualsWithDelta(0.0, GradingMetrics::rougeL('alpha beta gamma', 'delta epsilon zeta'), 0.001);
    }

    public function test_empty_inputs_are_zero(): void
    {
        $this->assertSame(0.0, GradingMetrics::rouge1('', 'something'));
        $this->assertSame(0.0, GradingMetrics::bleu('ref', ''));
    }

    public function test_lexical_blend_in_range(): void
    {
        $m = GradingMetrics::lexical('water boils at one hundred degrees', 'water boils at 100 celsius');
        $this->assertGreaterThan(0, $m['score']);
        $this->assertLessThanOrEqual(1.0, $m['score']);
        $this->assertArrayHasKey('rouge1', $m);
    }

    public function test_chi_square_survival_anchors(): void
    {
        $this->assertEqualsWithDelta(1.0, GradingStats::chiSquareSurvival(0, 1), 0.0001);
        // χ²=3.841, df=1 → p ≈ 0.05 (the classic critical value)
        $this->assertEqualsWithDelta(0.05, GradingStats::chiSquareSurvival(3.841, 1), 0.005);
        // χ²=5.991, df=2 → p ≈ 0.05
        $this->assertEqualsWithDelta(0.05, GradingStats::chiSquareSurvival(5.991, 2), 0.005);
        $this->assertLessThan(0.001, GradingStats::chiSquareSurvival(50, 1));
    }

    public function test_chi_square_alignment_verdict(): void
    {
        $perfect = GradingStats::chiSquare([80, 75, 90, 60], [80, 75, 90, 60]);
        $this->assertEqualsWithDelta(0.0, $perfect['chiSquare'], 0.001);
        $this->assertTrue($perfect['aligned']);          // p = 1 > 0.05

        $bad = GradingStats::chiSquare([10, 90, 20], [80, 30, 85]);
        $this->assertFalse($bad['aligned']);             // wildly different → p < 0.05
    }
}
