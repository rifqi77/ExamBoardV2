<?php

namespace Tests\Unit;

use App\Services\Shuffle;
use Tests\TestCase;

class ShuffleTest extends TestCase
{
    public function test_matches_known_js_output(): void
    {
        // Anchored to the Next.js implementation (verified byte-identical):
        // shuffleWithSeed(A..J, 'session-123::q') === E,B,I,G,C,A,D,H,J,F
        $arr = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $this->assertSame(['E', 'B', 'I', 'G', 'C', 'A', 'D', 'H', 'J', 'F'], Shuffle::shuffleWithSeed($arr, 'session-123::q'));
    }

    public function test_same_seed_is_stable(): void
    {
        $arr = range(1, 25);
        $this->assertSame(Shuffle::shuffleWithSeed($arr, 'seed-x'), Shuffle::shuffleWithSeed($arr, 'seed-x'));
    }

    public function test_different_seed_differs(): void
    {
        $arr = range(1, 25);
        $this->assertNotSame(Shuffle::shuffleWithSeed($arr, 'seed-a'), Shuffle::shuffleWithSeed($arr, 'seed-b'));
    }

    public function test_is_a_permutation(): void
    {
        $arr = range(1, 30);
        $out = Shuffle::shuffleWithSeed($arr, 'k');
        sort($out);
        $this->assertSame($arr, $out);
    }
}
