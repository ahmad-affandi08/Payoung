<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Referensi\Aksi;

use App\Domain\Referensi\Model\SatuanStandar;

/**
 * Satuan standar awal (P-02 "pcs, kg, liter, meter, dus, dll."). Idempoten: satuan yang sudah ada tidak diubah.
 */
final class SiapkanSatuanStandarBawaan
{
    /** @var list<array{0: string, 1: string, 2: string, 3: bool}> Kode, Nama, Simbol, BolehDesimal */
    private const DAFTAR = [
        ['PCS', 'Pcs', 'pcs', false],
        ['BUAH', 'Buah', 'bh', false],
        ['KG', 'Kilogram', 'kg', true],
        ['G', 'Gram', 'g', true],
        ['L', 'Liter', 'l', true],
        ['ML', 'Mililiter', 'ml', true],
        ['M', 'Meter', 'm', true],
        ['CM', 'Sentimeter', 'cm', true],
        ['DUS', 'Dus', 'dus', false],
        ['PAK', 'Pak', 'pak', false],
        ['LUSIN', 'Lusin', 'lsn', false],
        ['BOTOL', 'Botol', 'btl', false],
        ['LEMBAR', 'Lembar', 'lbr', false],
        ['PORSI', 'Porsi', 'porsi', false],
        ['JAM', 'Jam', 'jam', true],
        // Sektor Apotek (§9.5, template RTL-PHR): kemasan obat.
        ['STRIP', 'Strip', 'strip', false],
        ['TUBE', 'Tube', 'tube', false],
        // Sektor sayur/buah & sembako (RTL-FRS), florist (RTL-FLR), pet shop (RTL-PET), gym & kursus (SVC-FIT) (D-88).
        ['IKAT', 'Ikat', 'ikat', false],
        ['KARUNG', 'Karung', 'krg', false],
        ['BUNGKUS', 'Bungkus', 'bks', false],
        ['BUTIR', 'Butir', 'btr', false],
        ['TANGKAI', 'Tangkai', 'tkai', false],
        ['SESI', 'Sesi', 'sesi', false],
        ['BULAN', 'Bulan', 'bln', false],
        // Sektor Perusahaan tanpa kasir (D-167, BIZ-JSA/BIZ-KTR/BIZ-MFG): satuan jasa & proyek.
        ['HARI', 'Hari', 'hari', false],
        ['PAKET', 'Paket', 'paket', false],
        ['PROYEK', 'Proyek', 'proyek', false],
        ['UNIT', 'Unit', 'unit', false],
        ['M2', 'Meter persegi', 'm2', true],
    ];

    public function Jalankan(): void
    {
        foreach (self::DAFTAR as [$kode, $nama, $simbol, $bolehDesimal]) {
            SatuanStandar::query()->firstOrCreate(
                ['Kode' => $kode],
                ['Nama' => $nama, 'Simbol' => $simbol, 'BolehDesimal' => $bolehDesimal, 'Aktif' => true],
            );
        }
    }
}
