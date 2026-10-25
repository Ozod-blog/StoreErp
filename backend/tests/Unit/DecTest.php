<?php

namespace Tests\Unit;

use App\Support\Dec;
use PHPUnit\Framework\TestCase;

class DecTest extends TestCase
{
    public function test_mul_div_rounds_half_up_and_floor(): void
    {
        $this->assertSame(33, Dec::mulDiv(100, 1, 3));
        $this->assertSame(1, Dec::mulDiv(2, 1, 3));          // 0.67 -> 1
        $this->assertSame(0, Dec::mulDiv(2, 1, 3, 'floor'));
        $this->assertSame(8, Dec::mulDiv(5, 3, 2));          // 7.5 -> 8
        $this->assertSame(7, Dec::mulDiv(5, 3, 2, 'floor'));
        $this->assertSame(0, Dec::mulDiv(0, 5, 7));
    }

    public function test_mul_div_does_not_overflow_on_large_intermediate_products(): void
    {
        // 2e12 * 3e12 = 6e24 — 64 bitdan ancha katta, lekin natija aniq 1e12.
        $this->assertSame(1_000_000_000_000, Dec::mulDiv(2_000_000_000_000, 3_000_000_000_000, 6_000_000_000_000));
        $this->assertSame(500_000_000_000, Dec::mulDiv(1_000_000_000_000, 1_000_000_000_000, 2_000_000_000_000));
    }

    public function test_mul_div_handles_a_not_smaller_than_c(): void
    {
        $this->assertSame(1_000_000, Dec::mulDiv(2_000_000, 1_000, 2_000));
        $this->assertSame(10, Dec::mulDiv(25, 2, 5));
    }

    public function test_mul_div_rejects_invalid_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dec::mulDiv(1, 1, 0);
    }
}
