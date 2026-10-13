<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusCuti;
use App\Domain\Karyawan\Model\CutiKaryawan;
use Carbon\CarbonImmutable;

/**
 * Peta hari cuti/izin yang berlaku (F-18) untuk rentang tanggal: dasar rekap kehadiran, rekap gaji, notifikasi kehadiran,
 * dan tampilan jadwal. Dibaca dalam konteks tenant. Hanya catatan berstatus `Aktif` yang dihitung.
 */
final class CutiAktifKaryawan
{
    /**
     * @param  list<int>|null  $idKaryawan  null = semua karyawan
     * @return array<int, array<string, array{Jenis: string, Berbayar: bool}>> id karyawan → tanggal `YYYY-MM-DD` → cuti
     */
    public function AmbilPerTanggal(string $dari, string $sampai, ?array $idKaryawan = null): array
    {
        $hasil = [];
        $cuti = CutiKaryawan::query()
            ->where('Status', StatusCuti::Aktif->value)
            ->where('TanggalMulai', '<=', $sampai)
            ->where('TanggalSelesai', '>=', $dari)
            ->when($idKaryawan !== null, fn ($k) => $k->whereIn('IdKaryawan', $idKaryawan ?? []))
            ->orderBy('Id')
            ->get();

        foreach ($cuti as $c) {
            $awal = max($c->TanggalMulai->toDateString(), $dari);
            $akhir = min($c->TanggalSelesai->toDateString(), $sampai);

            for ($hari = CarbonImmutable::parse($awal, 'UTC'); $hari->toDateString() <= $akhir; $hari = $hari->addDay()) {
                $hasil[$c->IdKaryawan][$hari->toDateString()] = ['Jenis' => $c->Jenis->value, 'Berbayar' => $c->Berbayar];
            }
        }

        return $hasil;
    }
}
