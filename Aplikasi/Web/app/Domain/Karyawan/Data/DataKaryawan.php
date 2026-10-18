<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Data;

use App\Domain\Bersama\Nilai\Uang;

/** Masukan `SimpanKaryawan` (F-18). Teks opsional kosong = null. `ikutAbsensi` null = tidak diubah (karyawan baru: ikut). */
final readonly class DataKaryawan
{
    public function __construct(
        public string $nama,
        public ?string $jabatan,
        public ?string $levelStaf,
        public ?Uang $gajiPokok,
        public ?string $uuidPengguna,
        public ?string $uuidOutlet,
        public int $idPengguna,
        public ?Uang $tarifLemburPerJam = null,
        public ?Uang $potonganTerlambatPerMenit = null,
        public ?Uang $potonganTidakMasukPerHari = null,
        public ?bool $ikutAbsensi = null,
    ) {}
}
