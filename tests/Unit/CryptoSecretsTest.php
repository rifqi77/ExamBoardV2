<?php

namespace Tests\Unit;

use App\Services\CryptoSecrets;
use Tests\TestCase;

class CryptoSecretsTest extends TestCase
{
    public function test_token_preview_round_trip(): void
    {
        $enc = CryptoSecrets::encryptTokenPreview('8RTT24');
        $this->assertNotSame('8RTT24', $enc);
        $this->assertSame('8RTT24', CryptoSecrets::decryptTokenPreview($enc));
    }

    public function test_student_password_round_trip(): void
    {
        $enc = CryptoSecrets::encryptStudentPassword('athalla2026');
        $this->assertNotSame('athalla2026', $enc);
        $this->assertSame('athalla2026', CryptoSecrets::decryptStudentPassword($enc));
    }

    public function test_secret_round_trip(): void
    {
        $enc = CryptoSecrets::encryptSecret('sk-test-12345');
        $this->assertNotSame('sk-test-12345', $enc);
        $this->assertSame('sk-test-12345', CryptoSecrets::decryptSecret($enc));
    }

    public function test_wire_format_is_three_base64_parts(): void
    {
        // iv:tag:ciphertext, each base64
        $enc = CryptoSecrets::encryptSecret('x');
        $this->assertSame(3, count(explode(':', $enc)));
    }
}
