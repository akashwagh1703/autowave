<?php

namespace Tests\Feature\Crm;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneTest extends TestCase
{
    protected bool $seed = false;

    /** @return array<string, array{?string, ?string}> */
    public static function numbers(): array
    {
        return [
            'local ten digits' => ['98765 43210', '+919876543210'],
            'leading zero' => ['098765-43210', '+919876543210'],
            'with plus' => ['+91 98765 43210', '+919876543210'],
            'double zero prefix' => ['0091 98765 43210', '+919876543210'],
            'foreign number' => ['+44 20 7946 0958', '+442079460958'],
            'too short' => ['12345', null],
            'empty' => ['', null],
            'null' => [null, null],
            'letters only' => ['call me', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_phone_numbers_are_normalised_for_matching(?string $input, ?string $expected): void
    {
        config(['crm.default_country_code' => '91']);

        $this->assertSame($expected, Phone::normalize($input));
    }
}
