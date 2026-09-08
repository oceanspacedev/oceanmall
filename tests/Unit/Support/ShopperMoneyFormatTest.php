<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Tests\TestCase;

final class ShopperMoneyFormatTest extends TestCase
{
    public function test_idr_is_recognized_as_no_division_currency(): void
    {
        $this->assertTrue(is_no_division_currency('IDR'));
        $this->assertTrue(is_no_division_currency('idr'));
        $this->assertContains('IDR', zero_decimal_currencies());
    }

    public function test_shopper_money_format_formats_idr_without_division_and_with_rupiah_symbol(): void
    {
        $formattedItem = shopper_money_format(399000, 'IDR');
        $formattedOrder = shopper_money_format(406000, 'IDR');

        // Verify it contains Rp and dot-separated thousands without cents/decimals
        $this->assertStringContainsString('Rp', $formattedItem);
        $this->assertStringContainsString('399.000', $formattedItem);
        $this->assertStringNotContainsString('3,990', $formattedItem);

        $this->assertStringContainsString('Rp', $formattedOrder);
        $this->assertStringContainsString('406.000', $formattedOrder);
        $this->assertStringNotContainsString('4,060', $formattedOrder);
    }

    public function test_other_currencies_maintain_standard_behavior(): void
    {
        // USD is divided by 100
        $this->assertFalse(is_no_division_currency('USD'));
        $usd = shopper_money_format(1000, 'USD');
        $this->assertStringContainsString('10', $usd);

        // JPY is not divided by 100
        $this->assertTrue(is_no_division_currency('JPY'));
        $jpy = shopper_money_format(1000, 'JPY');
        $this->assertStringContainsString('1,000', $jpy);
    }
}
