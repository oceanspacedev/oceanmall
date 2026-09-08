<?php

declare(strict_types=1);

namespace App\Support;

final class CourierServiceExplainer
{
    public static function explain(string $carrierCode, string $serviceCode, ?string $rawDescription = null): string
    {
        $carrier = strtolower(trim($carrierCode));
        $service = strtoupper(trim($serviceCode));

        if (str_contains($service, ':')) {
            $parts = explode(':', $service);
            $service = strtoupper(end($parts));
        }

        $key = "{$carrier}:{$service}";

        return match ($key) {
            // JNE Services
            'jne:CTC' => 'Reguler Dalam Kota (City to City)',
            'jne:CTCYES' => 'Kilat 1 Hari Sampai (Besok Tiba)',
            'jne:REG' => 'Layanan Reguler Standar',
            'jne:YES' => 'Kilat 1 Hari Sampai (Yakin Esok Sampai)',
            'jne:OKE' => 'Ongkos Kirim Ekonomis (Hemat)',
            'jne:JTR' => 'Kargo Truk Darat (Hemat kirim banyak, min. 10 kg)',
            'jne:JTR<130', 'jne:JTR < 130' => 'Kargo Khusus Sepeda Motor (<130 cc)',
            'jne:JTR>130', 'jne:JTR > 130' => 'Kargo Khusus Sepeda Motor (130-200 cc)',
            'jne:JTR>200', 'jne:JTR > 200' => 'Kargo Khusus Sepeda Motor (>200 cc)',
            'jne:SPS' => 'Special Services (Pengiriman Khusus)',

            // J&T Services
            'jnt:EZ' => 'Layanan Reguler Standar J&T',
            'jnt:SUPER', 'jnt:J&T SUPER' => 'Layanan Kilat Prioritas (1-2 hari)',
            'jnt:ECO' => 'Layanan Hemat Jalur Darat/Laut',
            'jnt:JEMARI' => 'Layanan Ambil Sendiri di Drop Point',
            'jnt:CARGO' => 'Kargo Darat Paket Besar',

            // SiCepat Services
            'sicepat:SIUNT' => 'Reguler SiUntung (Standar)',
            'sicepat:BEST' => 'Besok Sampai Tujuan (1 hari)',
            'sicepat:GOKIL' => 'Kargo Kilat (Hemat kirim banyak, min. 10 kg)',
            'sicepat:HALU' => 'Layanan Ekonomis Hemat',
            'sicepat:CARGO' => 'Kargo SiCepat Paket Besar',

            // Anteraja Services
            'anteraja:REG', 'anteraja:REGULER' => 'Layanan Reguler Anteraja',
            'anteraja:ND', 'anteraja:NEXT DAY' => 'Layanan Kilat Esok Hari',
            'anteraja:ECO', 'anteraja:ECONOMY' => 'Layanan Ekonomis Hemat',
            'anteraja:CARGO' => 'Kargo Anteraja Paket Besar',

            // ID Express Services
            'ide:STD', 'idexpress:STD' => 'Layanan Reguler Standar ID Express',
            'ide:IDLITE', 'idexpress:IDLITE' => 'Paket Ringan Hemat (<500 gram)',
            'ide:SAMEDAY', 'idexpress:SAMEDAY' => 'Tiba di Hari yang Sama',

            // POS Indonesia Services
            'pos:POS REGULER' => 'Layanan Pos Reguler Nusantara',
            'pos:POS NEXTDAY' => 'Kiriman Pos Kilat 1 Hari',
            'pos:POS JUMBO EKONOMI' => 'Pos Kargo Paket Besar/Berat',

            default => self::formatDefault($service, $rawDescription),
        };
    }

    private static function formatDefault(string $service, ?string $rawDescription): string
    {
        if ($rawDescription !== null && trim($rawDescription) !== '') {
            $desc = trim($rawDescription);
            if (strcasecmp($desc, $service) !== 0) {
                return $desc;
            }
        }

        if (str_contains($service, 'JTR')) {
            return 'Kargo Truk JNE (Min. 10 kg)';
        }

        if (str_contains($service, 'CARGO') || str_contains($service, 'KARGO')) {
            return 'Layanan Kargo Paket Besar';
        }

        if (str_contains($service, 'REG')) {
            return 'Layanan Reguler Standar';
        }

        if (str_contains($service, 'EXP') || str_contains($service, 'FAST') || str_contains($service, 'KILAT')) {
            return 'Layanan Pengiriman Cepat';
        }

        if (str_contains($service, 'ECO') || str_contains($service, 'HEMAT')) {
            return 'Layanan Pengiriman Ekonomis';
        }

        return "Layanan {$service}";
    }
}
