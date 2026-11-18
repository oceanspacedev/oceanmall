<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use App\Models\OrderShipment;

interface PrintsLabels
{
    /**
     * Print the provider labels for shipments this driver registered.
     *
     * Throws a RuntimeException with a staff-facing message when the
     * shipments cannot be printed yet (not registered, no AWB...). Both
     * `label_url` and `pdf` null means "no provider document": the cpanel
     * falls back to its built-in thermal label.
     *
     * @param  iterable<OrderShipment>  $shipments
     * @param  string|null  $format  Driver-specific label format (page size...), null for the default
     * @return array{label_url: string|null, pdf: string|null, response?: array<string, mixed>}
     */
    public function printShipmentLabels(iterable $shipments, ?string $format = null): array;
}
