<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\KomerceLabelResponse;
use Tests\TestCase;

final class KomerceLabelResponseTest extends TestCase
{
    public function test_absolute_path_on_delivery_host_is_returned_as_is(): void
    {
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://api-sandbox.collaborator.komerce.id');

        $url = KomerceLabelResponse::absoluteUrl([
            'data' => ['path' => 'https://api-sandbox.collaborator.komerce.id/storage/label/RO-ORDER-001.pdf'],
        ]);

        $this->assertSame('https://api-sandbox.collaborator.komerce.id/storage/label/RO-ORDER-001.pdf', $url);
    }

    public function test_absolute_path_on_foreign_host_is_rejected(): void
    {
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://api-sandbox.collaborator.komerce.id');

        $url = KomerceLabelResponse::absoluteUrl([
            'data' => ['path' => 'https://cdn.example.test/label.pdf'],
        ]);

        $this->assertNull($url);
    }

    public function test_relative_path_is_prefixed_with_delivery_base_url(): void
    {
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://api-sandbox.collaborator.komerce.id');

        $url = KomerceLabelResponse::absoluteUrl([
            'data' => ['path' => '/storage/label-01.pdf'],
        ]);

        // Relative paths are served below the Shipping Delivery `/order` prefix.
        $this->assertSame(
            'https://api-sandbox.collaborator.komerce.id/order/storage/label-01.pdf',
            $url,
        );
    }

    public function test_base64_payload_decodes_to_pdf_bytes(): void
    {
        $bytes = KomerceLabelResponse::pdfBinary([
            'data' => ['base_64' => base64_encode('%PDF-1.4 demo')],
        ]);

        $this->assertSame('%PDF-1.4 demo', $bytes);
    }
}
