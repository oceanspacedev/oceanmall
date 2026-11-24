<?php

declare(strict_types=1);

namespace App\Shipping;

use RuntimeException;

/**
 * The shipment must not be booked with its shipping provider (anymore): the
 * order is cancelled / completed / archived or its payment was refunded /
 * voided, the parcel already left, or it already has an airway bill. An
 * unpaid order is NOT blocked here (COD ships before it is paid); callers
 * that book only paid orders filter on the payment status themselves. Thrown by the provider fulfilment services
 * right before a provider call (App\Shipping\ShipmentFulfillment::assertEligible());
 * the generic job and retry sweep treat it as "skip", never as a failure to
 * retry.
 */
final class ShipmentNotFulfillable extends RuntimeException {}
