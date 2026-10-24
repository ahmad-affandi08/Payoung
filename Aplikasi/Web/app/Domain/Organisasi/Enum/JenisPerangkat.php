<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/**
 * Jenis perangkat POS (PRD §15.3 `Perangkat.Jenis`, F-02 langkah 5). Huruf jenis membentuk kode perangkat untuk
 * penomoran offline `{KodeOutlet}-{Huruf}{NN}`, misal `JKT1-K02`. `Salesman` (§9.7, SLS-11) = HP salesman lapangan untuk
 * kunjungan pelanggan & ambil pesanan grosir (outbox `Kunjungan.Catat` & `PesananGrosir.Buat`), kode `JKT1-S01`.
 */
enum JenisPerangkat: string
{
    case Kasir = 'Kasir';
    case Kds = 'Kds';
    case Gudang = 'Gudang';
    case Pelayan = 'Pelayan';
    case Salesman = 'Salesman';

    /**
     * D-183: awalan kode sektor yang memakai jenis perangkat ini; null = umum.
     *
     * @return list<string>|null
     */
    public function AmbilSektor(): ?array
    {
        return match ($this) {
            self::Kds, self::Pelayan => ['FNB'],
            self::Salesman => ['WHS', 'RTL-BLD', 'BIZ-MFG'],
            default => null,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Kasir => 'Kasir',
            self::Kds => 'Layar dapur (KDS)',
            self::Gudang => 'Gudang',
            self::Pelayan => 'Pelayan',
            self::Salesman => 'Salesman',
        };
    }

    public function AmbilHuruf(): string
    {
        return match ($this) {
            self::Kasir => 'K',
            self::Kds => 'D',
            self::Gudang => 'G',
            self::Pelayan => 'P',
            self::Salesman => 'S',
        };
    }
}
