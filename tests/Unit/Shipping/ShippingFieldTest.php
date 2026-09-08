<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Filament\Forms\Components\Select;
use Shopper\Components\Form\ShippingField;
use Shopper\Core\Enum\Dimension\Weight;
use Tests\TestCase;

final class ShippingFieldTest extends TestCase
{
    public function test_weight_unit_only_contains_kg_and_g(): void
    {
        $fields = ShippingField::make();

        // 3rd field is weight_value (TextInputSelect)
        $weightField = $fields[2];
        $this->assertNotNull($weightField);

        $reflection = new \ReflectionClass($weightField);
        $selectProperty = $reflection->getProperty('selectComponentClosure');
        $selectProperty->setAccessible(true);
        $closure = $selectProperty->getValue($weightField);

        /** @var Select $select */
        $select = $closure();
        $options = $select->getOptions();

        $this->assertArrayHasKey('kg', $options);
        $this->assertArrayHasKey('g', $options);
        $this->assertArrayNotHasKey('lbs', $options);
        $this->assertCount(2, $options);
    }
}
