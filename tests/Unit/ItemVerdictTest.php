<?php

namespace Tests\Unit;

use App\Services\ItemAnalysis;
use PHPUnit\Framework\TestCase;

class ItemVerdictTest extends TestCase
{
    public function test_negative_discrimination_is_revise_or_retire(): void
    {
        $v = ItemAnalysis::verdict('single_choice', 0.5, -0.2, ['negative_discrimination']);
        $this->assertSame('retire', $v['level']);
    }

    public function test_difficulty_and_weakness_flags_are_review(): void
    {
        $this->assertSame('review', ItemAnalysis::verdict('single_choice', 0.95, 0.4, ['too_easy'])['level']);
        $this->assertSame('review', ItemAnalysis::verdict('single_choice', 0.1, 0.4, ['too_hard'])['level']);
        $this->assertSame('review', ItemAnalysis::verdict('single_choice', 0.5, 0.1, ['weak_discrimination'])['level']);
    }

    public function test_clean_item_is_keep(): void
    {
        $this->assertSame('keep', ItemAnalysis::verdict('single_choice', 0.6, 0.4, [])['level']);
    }

    public function test_essay_verdicts(): void
    {
        $this->assertSame('info', ItemAnalysis::verdict('essay', null, null, ['ungraded_essays'])['level']);
        $this->assertSame('keep', ItemAnalysis::verdict('essay', 0.7, null, [])['level']);
    }
}
