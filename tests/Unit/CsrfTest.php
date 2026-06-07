<?php

namespace Tests\Unit;

use App\Support\Csrf;
use Tests\TestCase;

class CsrfTest extends TestCase
{
    public function test_issued_token_is_valid(): void
    {
        $token = Csrf::issue();
        $this->assertStringContainsString('.', $token);
        $this->assertTrue(Csrf::valid($token));
    }

    public function test_tampered_or_malformed_tokens_are_rejected(): void
    {
        $token = Csrf::issue();
        [$random] = explode('.', $token, 2);

        $this->assertFalse(Csrf::valid($random.'.deadbeef'));   // forged signature
        $this->assertFalse(Csrf::valid($random));               // no signature
        $this->assertFalse(Csrf::valid(''));                    // empty
        $this->assertFalse(Csrf::valid(null));                  // missing
        $this->assertFalse(Csrf::valid($token.'x'));            // mutated
    }

    public function test_matches_requires_equal_and_valid_pair(): void
    {
        $token = Csrf::issue();

        $this->assertTrue(Csrf::matches($token, $token));       // cookie == header, valid
        $this->assertFalse(Csrf::matches($token, Csrf::issue())); // different valid tokens
        $this->assertFalse(Csrf::matches($token, null));        // header missing
        $this->assertFalse(Csrf::matches(null, $token));        // cookie missing
        $this->assertFalse(Csrf::matches('a.b', 'a.b'));        // equal but invalid signature
    }
}
