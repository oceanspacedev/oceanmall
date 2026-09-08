<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Support\CourierServiceExplainer;
use Tests\TestCase;

final class CourierServiceExplainerTest extends TestCase
{
    public function test_explains_jne_services(): void
    {
        $this->assertSame('Reguler Dalam Kota (City to City)', CourierServiceExplainer::explain('jne', 'CTC'));
        $this->assertSame('Kilat 1 Hari Sampai (Besok Tiba)', CourierServiceExplainer::explain('jne', 'CTCYES'));
        $this->assertSame('Layanan Reguler Standar', CourierServiceExplainer::explain('jne', 'REG'));
        $this->assertSame('Kilat 1 Hari Sampai (Yakin Esok Sampai)', CourierServiceExplainer::explain('jne', 'YES'));
        $this->assertSame('Kargo Truk Darat (Hemat kirim banyak, min. 10 kg)', CourierServiceExplainer::explain('jne', 'JTR'));
        $this->assertSame('Kargo Khusus Sepeda Motor (<130 cc)', CourierServiceExplainer::explain('jne', 'JTR<130'));
    }

    public function test_explains_jnt_services(): void
    {
        $this->assertSame('Layanan Reguler Standar J&T', CourierServiceExplainer::explain('jnt', 'EZ'));
        $this->assertSame('Layanan Kilat Prioritas (1-2 hari)', CourierServiceExplainer::explain('jnt', 'SUPER'));
    }

    public function test_explains_sicepat_services(): void
    {
        $this->assertSame('Reguler SiUntung (Standar)', CourierServiceExplainer::explain('sicepat', 'SIUNT'));
        $this->assertSame('Besok Sampai Tujuan (1 hari)', CourierServiceExplainer::explain('sicepat', 'BEST'));
        $this->assertSame('Kargo Kilat (Hemat kirim banyak, min. 10 kg)', CourierServiceExplainer::explain('sicepat', 'GOKIL'));
    }
}
