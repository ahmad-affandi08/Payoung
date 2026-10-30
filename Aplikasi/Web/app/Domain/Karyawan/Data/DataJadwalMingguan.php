<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Data;

use Carbon\CarbonImmutable;

/**
 * Jadwal satu minggu (Senin–Minggu) satu outlet (F-18). Tiap sel = satu karyawan pada satu tanggal dengan daftar shift
 * (D-251): jam `HH:mm` (dan opsional `UuidShift` asal), daftar kosong = hapus semua shift karyawan itu di outlet ini pada
 * hari tersebut. Daftar shift menggantikan isi hari itu seluruhnya.
 */
final readonly class DataJadwalMingguan
{
    /**
     * @param  list<array{UuidKaryawan: string, Tanggal: string, Shift: list<array{JamMulai: string, JamSelesai: string, UuidShift: string|null}>}>  $sel
     */
    public function __construct(
        public int $idOutlet,
        public CarbonImmutable $senin,
        public array $sel,
        public ?int $idPengguna,
    ) {}
}
