<?php

declare(strict_types=1);

namespace App\Shipping;

use RuntimeException;

/**
 * The shipment must not be booked with its shipping provider (anymore): the
 * order is cancelled / completed / not paid, the parcel already left, or it
 * already has an airway bill. Thrown by the provider fulfilment services
 * right before a provider call (App\Shipping\ShipmentFulfillment::assertEligible());
 * the generic job and retry sweep treat it as "skip", never as a failure to
 * retry.
 */
final class ShipmentNotFulfillable extends RuntimeException {}
