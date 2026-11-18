<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * The validation rules of the warehouse origin id field the cpanel location
 * form shows for this driver (alongside ResolvesDestinations, or on its own:
 * implementing it is enough for the field to be shown, see RequiresOriginId).
 *
 * Without it the field accepts any string up to 100 characters, which fits
 * area ids such as "IDNP6IDNC148IDND836IDZ12410". RajaOngkir, whose ids are
 * positive integers, narrows it to ['nullable', 'integer', 'min:1'].
 */
interface ValidatesOriginIds
{
    /**
     * Laravel validation rules for one origin id (the field is optional).
     *
     * @return list<mixed>
     */
    public function originIdRules(): array;
}
