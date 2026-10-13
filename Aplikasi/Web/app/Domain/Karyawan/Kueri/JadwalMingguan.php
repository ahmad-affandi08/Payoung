<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use Carbon\CarbonImmutable;

/**
 * Isian jadwal mingguan satu outlet (F-18): baris = karyawan aktif berpangkalan di outlet ini atau yang punya jadwal di
 * outlet ini minggu tersebut; kolom = Senin–Minggu. Sel jadwal di outlet lain ditandai (tidak bisa diubah dari
 * sini). `Cuti` memuat hari cuti/izin yang berlaku pada minggu itu (tanggal → jenis & berbayar) untuk tampil di sel jadwal.
 */
final class JadwalMingguan
{
    public function __construct(private readonly CutiAktifKaryawan $cuti) {}

    /**
     * @return array{Hari: list<string>, Baris: list<array{Uuid: string, Nama: string, Jabatan: string|null, Aktif: bool, Jadwal: array<string, array{JamMulai: string, JamSelesai: string, OutletLain: bool}|null>, Cuti: array<string, array{Jenis: string, Berbayar: bool}>}>}
     */
    public function Ambil(int $idOutlet, CarbonImmutable $senin): array
    {
        $hari = [];

        for ($i = 0; $i < 7; $i++) {
            $hari[] = $senin->addDays($i)->toDateString();
        }

        $jadwal = JadwalKerja::query()->whereBetween('Tanggal', [$hari[0], $hari[6]])->get();
        $idDiOutlet = $jadwal->where('IdOutlet', $idOutlet)->pluck('IdKaryawan')->unique()->all();
        $karyawan = Karyawan::query()
            ->where(fn ($k) => $k->where(fn ($q) => $q->where('IdOutlet', $idOutlet)->where('Status', StatusKaryawan::Aktif->value))->orWhereIn('Id', $idDiOutlet))
            ->orderBy('Nama')
            ->get();
        $cuti = $this->cuti->AmbilPerTanggal($hari[0], $hari[6], array_values(array_map('intval', $karyawan->pluck('Id')->all())));
        $baris = [];

        foreach ($karyawan as $k) {
            $sel = [];

            foreach ($hari as $tanggal) {
                $j = $jadwal->first(fn (JadwalKerja $j): bool => $j->IdKaryawan === $k->Id && $j->Tanggal->toDateString() === $tanggal);
                $sel[$tanggal] = $j === null ? null : ['JamMulai' => $j->JamMulai, 'JamSelesai' => $j->JamSelesai, 'OutletLain' => $j->IdOutlet !== $idOutlet];
            }

            $baris[] = ['Uuid' => $k->Uuid, 'Nama' => $k->Nama, 'Jabatan' => $k->Jabatan, 'Aktif' => $k->Status === StatusKaryawan::Aktif, 'Jadwal' => $sel, 'Cuti' => $cuti[$k->Id] ?? []];
        }

        return ['Hari' => $hari, 'Baris' => $baris];
    }
}
