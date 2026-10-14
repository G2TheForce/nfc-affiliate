<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_local_nepali_mobile_numbers_get_the_country_code(): void
    {
        foreach (['9812345678', '981-234 5678', '(981) 234.5678', '9779812345678', '+9779812345678', '009779812345678', '9612345678', '9712345678'] as $typed) {
            $this->assertMatchesRegularExpression('/^\+977(98|96|97)\d{8}$/', PhoneNumber::normalize($typed), $typed);
        }
        $this->assertSame('+9779812345678', PhoneNumber::normalize(' 981 234 5678 '));
    }

    public function test_other_input_is_only_compacted_for_the_validator(): void
    {
        $this->assertSame('+14155550100', PhoneNumber::normalize('+1 415 555 0100'));
        $this->assertSame('015123456', PhoneNumber::normalize('01-5123456'));
        $this->assertSame('12345', PhoneNumber::normalize('12345'));
        $this->assertNull(PhoneNumber::normalize(null));
    }
}
