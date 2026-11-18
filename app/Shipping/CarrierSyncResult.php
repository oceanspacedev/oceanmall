<?php

declare(strict_types=1);

namespace App\Shipping;

/**
 * Outcome of SyncsCarriers::syncCarriers().
 */
final readonly class CarrierSyncResult
{
    /**
     * @param  list<string>  $enabled  Carrier slugs the driver enabled
     * @param  list<string>  $disabled  Carrier slugs the driver disabled
     * @param  list<string>  $headers  Optional report table headers
     * @param  list<list<string>>  $rows  Optional report table rows
     */
    public function __construct(
        public bool $successful,
        public string $message = '',
        public array $enabled = [],
        public array $disabled = [],
        public array $headers = [],
        public array $rows = [],
    ) {}

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
