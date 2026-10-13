<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Data;

use App\Domain\Bengkel\Enum\JenisUnitServis;

/** Isian unit servis pelanggan (§9.10, D-142) dari back-office; `nomorPolisi` (plat atau IMEI/seri) dinormalisasi oleh Aksi. */
final readonly class DataKendaraan
{
    public function __construct(
        public string $uuidPelanggan,
        public ?string $nomorPolisi,
        public string $merek,
        public ?string $tipe,
        public ?int $tahun,
        public ?string $warna,
        public ?string $nomorRangka,
        public ?string $nomorMesin,
        public ?int $kmTerakhir,
        public ?string $catatan,
        public bool $aktif = true,
        public JenisUnitServis $jenis = JenisUnitServis::Kendaraan,
    ) {}
}
