<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Support;

/**
 * The KiriminAja location vocabulary as one string id.
 *
 * Tariffs are per kecamatan (district) id; orders also take the kelurahan
 * (subdistrict / village) id, which some couriers (POS, RPX, Paxel) need for
 * pricing too. The address search (GET /api/mitra/v6.1/addresses) returns
 * both, so a destination / warehouse origin id is stored as
 * "{kecamatan_id}:{kelurahan_id}" (e.g. "2275:46310"). A bare "{kecamatan_id}"
 * (typed in cpanel) is accepted; the kelurahan is then looked up from the
 * postal code when an order needs it.
 */
final readonly class KiriminAjaDestination
{
    public const PATTERN = '/^\d{1,9}(:\d{1,9})?$/';

    public function __construct(
        public int $district,
        public ?int $subdistrict = null,
    ) {}

    public static function parse(mixed $id): ?self
    {
        $id = is_scalar($id) ? trim((string) $id) : '';

        if (preg_match(self::PATTERN, $id) !== 1) {
            return null;
        }

        [$district, $subdistrict] = array_pad(explode(':', $id, 2), 2, null);

        if ((int) $district < 1) {
            return null;
        }

        return new self((int) $district, $subdistrict !== null && (int) $subdistrict > 0 ? (int) $subdistrict : null);
    }

    public function id(): string
    {
        return $this->subdistrict !== null ? $this->district.':'.$this->subdistrict : (string) $this->district;
    }

    /**
     * One search row in the neutral ResolvesDestinations shape. full_address
     * reads "kelurahan, kecamatan, city, province, postal code".
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function searchRow(array $row): ?array
    {
        $district = $row['district_id'] ?? null;
        $subdistrict = $row['subdistrict_id'] ?? null;

        if (! is_numeric($district) || (int) $district < 1) {
            return null;
        }

        $label = is_scalar($row['full_address'] ?? null) ? trim((string) $row['full_address']) : '';
        $parts = array_values(array_filter(array_map('trim', explode(',', $label)), static fn (string $part): bool => $part !== ''));
        $postal = null;

        if ($parts !== [] && preg_match('/^\d{5}$/', $parts[array_key_last($parts)]) === 1) {
            $postal = array_pop($parts);
        }

        $count = count($parts);
        $location = new self((int) $district, is_numeric($subdistrict) && (int) $subdistrict > 0 ? (int) $subdistrict : null);

        return [
            'id' => $location->id(),
            'label' => $label !== '' ? $label : $location->id(),
            'postal_code' => $postal,
            'state' => $count >= 1 ? $parts[$count - 1] : null,
            'city' => $count >= 2 ? $parts[$count - 2] : null,
            'district' => $count >= 3 ? $parts[$count - 3] : null,
            'subdistrict' => $count >= 4 ? implode(', ', array_slice($parts, 0, $count - 3)) : null,
            'kecamatan_id' => (int) $district,
            'kelurahan_id' => $location->subdistrict,
        ];
    }
}
