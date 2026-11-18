<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * Marker: quoting or fulfilling with this driver needs a provider destination
 * id (address metadata `destination_id`) and a warehouse origin id
 * (inventory_shipping_origins) from the destination driver's vocabulary.
 */
interface RequiresDestination {}
