<?php

namespace Tests\Unit;

use App\Services\Users\AccessCodeGenerator;
use Tests\TestCase;

class AccessCodeGeneratorTest extends TestCase
{
    public function test_owner_code_is_reserved(): void
    {
        $generator = new AccessCodeGenerator;

        $this->assertTrue($generator->isReserved(AccessCodeGenerator::OWNER_CODE));
    }

    public function test_generated_code_is_six_digits_and_not_owner_code(): void
    {
        $generator = new AccessCodeGenerator;
        $code = $generator->generate();

        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame(AccessCodeGenerator::OWNER_CODE, $code);
    }

    public function test_generated_codes_do_not_repeat_existing_database_codes(): void
    {
        $generator = new AccessCodeGenerator;
        $existingCode = $generator->generate();

        $this->assertFalse($generator->isReserved($existingCode));

        $nextCode = $generator->generate();

        $this->assertNotSame($existingCode, $nextCode);
    }
}
