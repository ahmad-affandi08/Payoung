<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Enum;

/**
 * Sektor usaha prospek (D-216): kode template sektor Payoung. Impor CSV menerima kode (`FNB-CAF`) atau nama sektornya
 * ("Kafe & kedai kopi"), tanpa membedakan huruf besar kecil.
 */
enum SektorProspek: string
{
    case KafeKopi = 'FNB-CAF';
    case CepatSaji = 'FNB-QSR';
    case Restoran = 'FNB-RST';
    case Bakery = 'FNB-BAK';
    case Kelontong = 'RTL-GEN';
    case SayurSembako = 'RTL-FRS';
    case Fashion = 'RTL-FSH';
    case Elektronik = 'RTL-ELC';
    case BahanBangunan = 'RTL-BLD';
    case Apotek = 'RTL-PHR';
    case PetShop = 'RTL-PET';
    case Florist = 'RTL-FLR';
    case Grosir = 'WHS-DST';
    case Salon = 'SVC-SLN';
    case Laundry = 'SVC-LDR';
    case Bengkel = 'SVC-WRK';
    case Gym = 'SVC-FIT';
    case CuciKendaraan = 'SVC-CWS';
    case JasaUmum = 'SVC-GEN';
    case JasaProfesional = 'BIZ-JSA';
    case Kontraktor = 'BIZ-KTR';
    case Manufaktur = 'BIZ-MFG';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::KafeKopi => 'Kafe & kedai kopi',
            self::CepatSaji => 'Cepat saji & kaki lima',
            self::Restoran => 'Restoran & rumah makan',
            self::Bakery => 'Bakery & toko kue',
            self::Kelontong => 'Toko kelontong & minimarket',
            self::SayurSembako => 'Toko sayur, buah & sembako',
            self::Fashion => 'Fashion & aksesoris',
            self::Elektronik => 'Elektronik & gadget',
            self::BahanBangunan => 'Bahan bangunan',
            self::Apotek => 'Apotek & toko obat',
            self::PetShop => 'Pet shop & grooming',
            self::Florist => 'Florist & toko hadiah',
            self::Grosir => 'Grosir & distributor',
            self::Salon => 'Salon, barbershop & spa',
            self::Laundry => 'Laundry',
            self::Bengkel => 'Bengkel motor & mobil',
            self::Gym => 'Gym, kebugaran & kursus',
            self::CuciKendaraan => 'Cuci kendaraan',
            self::JasaUmum => 'Jasa umum & percetakan',
            self::JasaProfesional => 'Jasa profesional & agensi',
            self::Kontraktor => 'Kontraktor & proyek',
            self::Manufaktur => 'Manufaktur & produksi',
        };
    }

    /** Kode atau nama sektor (huruf besar kecil bebas) menjadi sektornya; null bila tidak dikenal. */
    public static function DariTeks(string $teks): ?self
    {
        $teks = mb_strtolower(trim($teks));

        foreach (self::cases() as $sektor) {
            if ($teks === mb_strtolower($sektor->value) || $teks === mb_strtolower($sektor->AmbilLabel())) {
                return $sektor;
            }
        }

        return null;
    }
}
